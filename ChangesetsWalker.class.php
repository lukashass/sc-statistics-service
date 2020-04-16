<?php

require_once 'ChangesetsWalkerStateDao.class.php';
require_once 'ChangesetsDao.class.php';
require_once 'ChangesetsFetcher.class.php';

/** Walks through a user's changeset history to find the relevant information for StreetComplete
 *  statistics.
 *
 *  The OSM API for querying a user's changeset history only returns up to 100 results but at the 
 *  same time does not support real pagination. One can only limit the results by a date-time-range.
 *
 *  So, the algorithm to get all changesets of a user is to walk from the most current changeset to 
 *  the first changeset. However, when we finally reached the first changeset, new changesets may 
 *  have been added in the meantime. So we need to again walk to the changeset that was the newest
 *  on the last run, etc.
 *
 *  As the walking through the history may take a long time, it is taken into consideration that
 *  the process is cancelled at any time because it takes too long. This is why after each chunk of
 *  100 changesets, the current state and the changesets are persisted before continuing.
 *  */
class ChangesetsWalker
{
    private $changesetsFetcher;
    private $changesetsDao;
    private $changesetsWalkerStateDao;
    
    public function __construct(mysqli $mysqli, string $osm_user = null, string $osm_pass = null)
    {
        $this->mysqli = $mysqli;
        $this->changesetsFetcher = new ChangesetsFetcher($osm_user, $osm_pass);
        $this->changesetsDao = new ChangesetsDao($mysqli);
        $this->changesetsWalkerStateDao = new ChangesetsWalkerStateDao($mysqli);
    }
    
    public function analyzeUser(int $user_id)
    {
        do {
            $range = $this->changesetsWalkerStateDao->getCurrentAnalyzingRange($user_id);
            $closed_after = $range[0];
            $created_before = $range[1];
            
            // TODO remove echos 
            echo "analyzing " . date("c",$closed_after) . " -> " . date("c",$created_before) . "\n";
            
            $changesets = $this->changesetsFetcher->fetchForUser($user_id, $closed_after, $created_before);
            // OSM API doesn't know this user: clear and cancel
            if (!isset($changesets)) {
                $this->changesetsWalkerStateDao->clearUser($user_id);
                $this->changesetsDao->clearUser($user_id);
                return;
            }
            echo "found " . count($changesets) . " changesets." . "\n";
            // break if no changesets have been found
            if (count($changesets) == 0) break;
            
            $sc_changesets = array(); // only SC changesets that are relevant for StreetComplete stats
            $oldest_created_date = NULL;
            $newest_closed_date = NULL;
            foreach ($changesets as $changeset) {
                if (!isset($oldest_created_date)) {
                    $oldest_created_date = $changeset->created_at;
                } else {
                    $oldest_created_date = min($oldest_created_date, $changeset->created_at);
                }
                if (!isset($newest_closed_date)) {
                    $newest_closed_date = $changeset->closed_at;
                } else {
                    $newest_closed_date = max($newest_closed_date, $changeset->closed_at);
                }
                if (isset($changeset->quest_type)) {
                    array_push($sc_changesets, $changeset);
                }
            }
            
            if (!empty($sc_changesets)) {
                $this->changesetsDao->putChangesets($sc_changesets);
            }
            
            /* break condition: The closed date of the newest changeset in the fetch result is 
               equal to the date before which the user's changeset history has been analyzed 
               already and this is the first call to fetch the changesets for a range */
            if ($newest_closed_date == $closed_after && !isset($created_before)) break;
            
            // OSM API always returns 100 unless there are no more -> we reached the end
            $range_is_done = count($changesets) < 100;
            $this->changesetsWalkerStateDao->updateAnalyzingRange(
                $user_id, $newest_closed_date, $oldest_created_date, $range_is_done
            );
        // TODO additional break condition? F.e. after a certain time has passed etc?
        } while(true);
        
       $this->recheckOpenChangesets($user_id);
    }

    private function recheckOpenChangesets(int $user_id) {
        $open_changesets_ids = $this->changesetsDao->getOpenChangesetIds($user_id);
        if (!empty($open_changesets_ids)) {
            $previously_open_changesets = $this->changesetsFetcher->fetchByIds($open_changesets_ids);
            if (!empty($previously_open_changesets)) {
                $this->changesetsDao->putChangesets($previously_open_changesets);
            }
        }
    }
}