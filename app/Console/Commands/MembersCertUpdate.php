<?php

namespace App\Console\Commands;

use App\Models\Mship\Account;
use App\Models\Mship\Qualification as QualificationData;
use Carbon\Carbon;
use VatsimXML;
use Exception;
use DB;

class MembersCertUpdate extends aCommand {

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'Members:CertUpdate
                        {max_members=1000}
                        {--t|type=all : Which update are we running? Hourly, Daily, Weekly or Monthly?}
                        {--f|force=0 : If specified, only this CID will be checked.}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update members using the cert feeds, if they have not had an update in 24 hours.';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function fire() {
        // set the maximum number of members to load, with a hard limit of 10,000
        if ($this->argument('max_members') > 10000) $max_members = 10000;
        else $max_members = $this->argument('max_members');

        // if we only want to force update a specific member, process them and exit
        if ($this->option("force-update")) {
            try {
                $member = Account::findOrFail($this->option("force-update"));
            } catch (Exception $e) {
                echo "\tError: cannot retrieve member " . $this->option("force-update") . " during forced update - " . $e->getMessage();
                exit(1);
            }

            $this->processMember($member);
            exit(0);
        }

        // all accounts should be loaded with their states, emails, and qualifications
        $members = Account::with('states')->with('emails')->with('qualifications');

        // add parameters based on the cron type
        $type = $this->option("type")[0];
        switch($type) {
            case "h":
                // members who have logged in in the last 30 days or who have never been checked
                $members = $members->where('last_login', '>=', Carbon::now()->subMonth()->toDateTimeString())
                                   ->orWhereNull('cert_checked_at');
                $this->log("Hourly cron.");
                break;
            case "d":
                // members who have logged in in the last 90 days and haven't been checked today
                $members = $members->where('cert_checked_at', '<=', Carbon::now()->subHours(23)->toDateTimeString())
                                   ->where('last_login', '>=', Carbon::now()->subMonths(3)->toDateTimeString());
                $this->log("Daily cron.");
                break;
            case "w":
                // members who have logged in in the last 180 days and haven't been checked this week
                $members = $members->where('cert_checked_at', '<=', Carbon::now()->subDays(6)->toDateTimeString())
                                   ->where('last_login', '>=', Carbon::now()->subMonths(6)->toDateTimeString());
                $this->log("Weekly cron.");
                break;
            case "m":
                // members who have never logged in and haven't been checked this month, but are still active VATSIM members
                $members = $members->where('cert_checked_at', '<=', Carbon::now()->subDays(25)->toDateTimeString())
                                   ->whereNull('last_login')
                                   ->where("status", "=", "0");
                $this->log("Monthly cron.");
                break;
            default:
                // all members
                $this->log("Full cron.");
                break;
        }

        $members = $members->orderBy('cert_checked_at', 'ASC')
                           ->limit($max_members)
                           ->get();

        if (count($members) < 1) {
            $this->log("No members to process.\n");
            return;
        }

        $this->log(count($members) . " retrieved.\n");

        foreach ($members as $pointer => $_m) {
            // remove members we don't want to process
            if ($_m->account_id < 800000) continue;

            $this->processMember($_m, $pointer);
        }

        $this->log("Processed " . ($pointer + 1) . " members.\n");
    }


    private function processMember($_m, $pointer = 0) {
        $log = "#" . ($pointer + 1) . " Processing " . str_pad($_m->account_id, 9, " ", STR_PAD_RIGHT) . "\t";

        // Let's load the details from VatsimXML!
        try {
            $_xmlData = VatsimXML::getData($_m->account_id, "idstatusint");
            $log .= "\tVatsimXML Data retrieved.\n";
        } catch (Exception $e) {
            $log .= "\tVatsimXML Data *NOT* retrieved.  ERROR.\n";
            return;
        }

        if ($_xmlData->name_first == new \stdClass() && $_xmlData->name_last == new \stdClass()
                && $_xmlData->email == "[hidden]") {
            $_m->delete();
            $log .= "\t" . $_m->account_id . " no longer exists in CERT - deleted.\n";
            return;
        }

        DB::beginTransaction();
        $log .= "\tDB::beginTransaction\n";
        try {
            $changed = FALSE;
            if (!empty($_xmlData->name_first) && is_string($_xmlData->name_first)) $_m->name_first = $_xmlData->name_first;
            if (!empty($_xmlData->name_last) && is_string($_xmlData->name_last)) $_m->name_last = $_xmlData->name_last;

            $log .="\t" . str_repeat("-", 89) . "\n";
            $log .="\t| Data Field\t\tOld Value\t\t\tNew Value\t\t\t|\n";
            if ($_m->isDirty()) {
                $original = $_m->getOriginal();
                foreach ($_m->getDirty() as $key => $newValue) {
                    $changed = TRUE;
                    $this->outputTableRow($key, array_get($original, $key, ""), $newValue);
                }
            }

            $_m->cert_checked_at = Carbon::now()->toDateTimeString();
            $_m->save();
            $_m = $_m->find($_m->account_id);

            // Let's work out the user status.
            $oldStatus = $_m->status;
            $_m->is_inactive = (boolean) ($_xmlData->rating < 0);
            if ($oldStatus != $_m->status) {
                $this->outputTableRow("status", $oldStatus, $_m->status_string);
                $changed = TRUE;
            }

            // Are they network banned, but unbanned in our system?
            // Add it!
            if($_xmlData->rating == 0 && $_m->is_network_banned === false){
                // Add a ban.
                $newBan = new \App\Models\Mship\Account\Ban();
                $newBan->type = \App\Models\Mship\Account\Ban::TYPE_NETWORK;
                $newBan->reason_extra = "Network ban discovered via Cert update scripts.";
                $newBan->period_start = \Carbon\Carbon::now();
                $newBan->save();

                $_m->bans()->save($newBan);
                Account::find(VATSIM_ACCOUNT_SYSTEM)->bansAsInstigator($newBan);
            }

            // Are they banned in our system (for a network ban) but unbanned on the network?
            // Then expire the ban.
            if($_m->is_network_banned === true && $_xmlData->rating > 0){
                $ban = $_m->network_ban;
                $ban->period_finish = \Carbon\Carbon::now();
                $ban->setPeriodAmountFromTS();
                $ban->save();
            }

            // Set their VATSIM registration date.
            $oldDate = $_m->joined_at;
            $newDate = $_xmlData->regdate;
            if ($oldDate != $newDate) {
                $_m->joined_at = $newDate;
                $this->outputTableRow("joined_at", $oldDate, $newDate);
                $changed = TRUE;
            }

            // If they're in this feed, they're a division member.
            $oldState = ($_m->current_state ? $_m->current_state->state : 0);
            $_m->determineState($_xmlData->region, $_xmlData->division);

            if ($oldState != $_m->current_state->state) {
                $this->outputTableRow("state", $oldState, $_m->current_state);
                $changed = TRUE;
            }

            // Sort their rating(s) out - we're not permitting instructor ratings if they're NONE UK members.
            if(($_xmlData->rating != 8 AND $_xmlData->rating != 9) OR $_m->current_state->state == \App\Models\Mship\Account\State::STATE_DIVISION){
                $atcRating = QualificationData::parseVatsimATCQualification($_xmlData->rating);
                $oldAtcRating = $_m->qualifications()->atc()->orderBy("created_at", "DESC")->first();
                if ($_m->addQualification($atcRating)) {
                    $this->outputTableRow("atc_rating", ($oldAtcRating ? $oldAtcRating->code : "None"), $atcRating->code);
                    $changed = TRUE;
                }
            }

            // If their rating is ABOVE INS1 (8+) then let's get their last.
            if ($_xmlData->rating >= 8) {
                $_prevRat = VatsimXML::getData($_m->account_id, "idstatusprat");
                if (isset($_prevRat->PreviousRatingInt)) {
                    $prevAtcRating = QualificationData::parseVatsimATCQualification($_prevRat->PreviousRatingInt);
                    if ($_m->addQualification($prevAtcRating)) {
                        $this->outputTableRow("atc_rating", "Previous", $prevAtcRating->code);
                        $changed = TRUE;
                    }
                }
            } else {
                // remove any extra ratings
                foreach (($q = $_m->qualifications_atc_training) as $qual) {
                    $changed = TRUE;
                    $qual->delete();
                }
                foreach (($q = $_m->qualifications_pilot_training) as $qual) {
                    $changed = TRUE;
                    $qual->delete();
                }
                foreach (($q = $_m->qualifications_admin) as $qual) {
                    $changed = TRUE;
                    $qual->delete();
                }
            }

            $pilotRatings = QualificationData::parseVatsimPilotQualifications($_xmlData->pilotrating);
            foreach ($pilotRatings as $pr) {
                if ($_m->addQualification($pr)) {
                    $changed = TRUE;
                    $this->outputTableRow("pilot_rating", "n/a", $pr->code);
                }
            }

            $_m->save();

        } catch (Exception $e) {
            DB::rollback();
            print "\tDB::rollback\n";
            print "\tError: " . $e->getMessage() . " on line " . $e->getLine() . " in " . $e->getFile() . "\n";
            print "\tCID: " . $_m->account_id . "\n";
        }

        $log .="\t" . str_repeat("-", 89) . "\n";

        DB::commit();
        $log .="\tDB::commit\n";
        $this->log($log);
    }

    private function outputTableRow($key, $old, $new) {
        $this->log("\t| " . str_pad($key, 20, " ", STR_PAD_RIGHT) . "\t" . str_pad($old, 30, " ", STR_PAD_RIGHT) . "\t" . str_pad($new, 30, " ", STR_PAD_RIGHT) . "\t|");
    }
}
