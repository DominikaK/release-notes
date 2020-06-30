<?php


// Array of JIRA ticket the script could not get information from
$failed_tickets = [];

// Array of repositories the script could not get information from
// because it could not figure out the current or the previous version
$failed_repositories = [];

// Gets a file from GitHub
function get_from_github($file)
{
    // From https://stackoverflow.com/a/23391557/7482889
    // This is probably terribly heretical
    // FIXME
    $curl_url = $file;
    $curl_token_auth = 'Authorization: token ' . $GLOBALS["token"];
    $ch = curl_init($curl_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('User-Agent: Release Notes', $curl_token_auth));
    $curl_output = curl_exec($ch);
    echo curl_error($ch);
    curl_close($ch);

    return $curl_output;
}

function get_from_jira($file)
{
    // https://community.developer.atlassian.com/t/login-into-jira-with-jira-rest-api-cookie-based-php/11222
    $ch = curl_init('https://jira.ez.no/rest/auth/1/session');
    $jsonData = array('DominikaK', 'bla');
//    $jsonData = array( 'username' => $_POST['username'], 'password' => $_POST['password'] );
    $jsonDataEncoded = json_encode($jsonData);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonDataEncoded);

    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));

    $result = curl_exec($ch);
    curl_close($ch);

    $sess_arr = json_decode($result, true);

    echo '<pre>';
    var_dump($ch);
    var_dump($sess_arr);
    echo'</pre>';

    return($result);
}

function create_release_notes($repo)
{
    $repository = $repo[0];
    $to_version = $repo[1];
    $from_version = $repo[2];
    $from_version_single = $repo[3];

    // Create compare file for the current repository
    $compare_file = "https://api.github.com/repos/ezsystems/$repository/compare/v$from_version...v$to_version";

    //This is the file to use when a repo needs different release notes than meta
    $compare_file_single = "";
    if (($from_version !== $from_version_single) && ($from_version_single !== "")) {
        $compare_file_single = "https://api.github.com/repos/ezsystems/$repository/compare/v$from_version_single...v$to_version";
    }

    print_r("\n" . $repository . ":\n");

    // If versions for meta and single repo are the same
    if ($compare_file_single =="")
    {
        // build regular release notes
        build_release_notes($compare_file, $from_version, $to_version, $repository, true, false);
    }
    // If there are different version for meta and for single repo
    else
    {
        // build regular release notes that will be used for meta
        build_release_notes($compare_file, $from_version, $to_version, $repository, true, true);
        // and build release notes that will not be used in meta, but instead in the repo
        build_release_notes($compare_file_single, $from_version_single, $to_version, $repository, false, false);
    }
}

function strip_version($version)
{
    if ($version[0] = "v") {
        $version = substr($version, 1);
    }

    return $version;
}

function build_release_notes($compare_file, $from_version, $to_version, $repository, $for_meta = true, $delete = false)
{
    $json_output = json_decode(get_from_github($compare_file));

    if (!isset($json_output->commits)) {
        print_r("Could not determine versions to compare for this repository.\nPlease create release notes for this repo manually.\n");
        array_push($GLOBALS["failed_repositories"], $repository);
    } else {
        $commit_list = $json_output->commits;

        print_r("Going through changes in " . $repository . " between " . $from_version . " and " . $to_version . "...\n");

        $entries = [];

        //Go through commit list and get jira ticket, summary and pr number into array
        foreach ($commit_list as $commit) {
            //Read commit message
            $commit_name = $commit->commit->message;

            // Exclude commits with [Behat] in their name
            if (preg_match('/\Q[Behat]\E/i', $commit_name)) {
                continue;
            }

            //Check if there is a jira project and ticket number
            $ticket = preg_match('/(((EZP)|(EZEE)|(DEMO)|(EC))-[[:digit:]]+)/', $commit_name, $matches_ticket);
            //Check if there is a PR number
            $pr = preg_match('/((?<=[#])[[:digit:]]+)/', $commit_name, $matches_pr);

            // If there is no ticket in the commit message and there is a PR, look through PR description
            if ($ticket === 0 && $pr === 1) {
                $pr_output = json_decode(get_from_github("https://api.github.com/repos/ezsystems/$repository/pulls/$matches_pr[0]"));
                $pr_body = $pr_output->body;
                //Override ticket with the ticket info taken from PR description
                $ticket = preg_match('/(((EZP)|(EZEE)|(DEMO)|(EC))-[[:digit:]]+)/', $pr_body, $matches_ticket);
            }

            //If there is a ticket in commit message or we got one from the PR
            if ($ticket === 1) {
                // Ugly hack in case of commerce
                $is_ec = preg_match('/(EC)/', $commit_name);
                if ($is_ec == 1) {
                    $summary = strtok($commit_name, "\n");
                    $issue_type = 'improvement';
                    $is_misc = true;

                    if ($pr === 1) {
                        $entries[$matches_ticket[0]] = array($summary, $issue_type, $is_misc, $matches_pr[0]);
                    } else {
                        $entries[$matches_ticket[0]] = array($summary, $issue_type, $is_misc, '');
                    }
                } else {

                    //Gets jira info for the current ticket
//                $jira_file = get_from_jira("https://jira.ez.no/rest/api/2/issue/$matches_ticket[0]");
                    $jira_file = "https://jira.ez.no/rest/api/2/issue/$matches_ticket[0]";

                    //Converts api output to json
                    $jira_json = file_get_contents($jira_file);
                    $jira_output = json_decode($jira_json);

                    echo json_encode($jira_json);

                    if (!isset($jira_output->id)) {
                        $GLOBALS["failed_tickets"] += [$repository => $matches_ticket[0]];
                    } else {
                        // Gets Summary field for the current ticket
                        $summary = $jira_output->fields->summary;
                        $issue_type = $jira_output->fields->issuetype->name;
                        $is_misc = false;

                        // Checks if issue has QA component
                        $issue_components = $jira_output->fields->components;
                        foreach ($issue_components as $component) {
                            if ($component->name == "QA") {
                                $is_misc = true;
                            }
                        }

                        //Add ticket number, summary and pr number to array
                        if ($pr === 1) {
                            $entries[$matches_ticket[0]] = array($summary, $issue_type, $is_misc, $matches_pr[0]);
                        } else {
                            $entries[$matches_ticket[0]] = array($summary, $issue_type, $is_misc, '');
                        }
                    }
                }
            }
        }

        if (empty($entries)) {
            print_r("No changes.\n");
        } else {
            $misc_list = [];
            $bugs_list = [];
            $improvements_list = [];

            //Go through all tickets and add result to respective lists
            foreach ($entries as $ticket_out => $key) {
                // If issue has QA component, place it in Misc
                if ($key[2] == true) {
                    $misc_list += [$ticket_out => $key];
                } // If issue is a Bug, place it in Bugs
                elseif ($key[1] === "Bug") {
                    $bugs_list += [$ticket_out => $key];
                } // Else place it in improvements
                else {
                    $improvements_list += [$ticket_out => $key];
                }
            }

            //Create temporary output bug and improvement files
            $misc_file = "misc_$repository$to_version.md";
            $bugs_file = "bugs_$repository$to_version.md";
            $improvements_file = "improvements_$repository$to_version.md";
            $fmisc = fopen($misc_file, "w+");
            $fbug = fopen($bugs_file, "w+");
            $fimp = fopen($improvements_file, "w+");

            if (!empty($improvements_list)) {
                fwrite($fimp, "### Improvements\n\n");

                foreach ($improvements_list as $ticket => $pr) {
                    // Only show PR link when there actually is a PR linked with the task
                    if ($pr[3]) {
                        $line = "- [$ticket](https://jira.ez.no/browse/$ticket): $pr[0] ([#$pr[3]](https://github.com/ezsystems/$repository/pull/$pr[3]))\n";
                    } else {
                        $line = "- [$ticket](https://jira.ez.no/browse/$ticket): $pr[0] \n";
                    }
                    fwrite($fimp, $line);
                }
            }

            if (!empty($bugs_list)) {
                fwrite($fbug, "### Bugs\n\n");
                foreach ($bugs_list as $ticket => $pr) {
                    // Only show PR link when there actually is a PR linked with the task
                    if ($pr[3]) {
                        $line = "- [$ticket](https://jira.ez.no/browse/$ticket): $pr[0] ([#$pr[3]](https://github.com/ezsystems/$repository/pull/$pr[3]))\n";
                    } else {
                        $line = "- [$ticket](https://jira.ez.no/browse/$ticket): $pr[0] \n";
                    }
                    fwrite($fbug, $line);
                }
            }

            if (!empty($misc_list)) {
                fwrite($fmisc, "### Misc\n\n");
                foreach ($misc_list as $ticket => $pr) {
                    // Only show PR link when there actually is a PR linked with the task
                    if ($pr[3]) {
                        $line = "- [$ticket](https://jira.ez.no/browse/$ticket): $pr[0] ([#$pr[3]](https://github.com/ezsystems/$repository/pull/$pr[3]))\n";
                    } else {
                        $line = "- [$ticket](https://jira.ez.no/browse/$ticket): $pr[0] \n";
                    }
                    fwrite($fmisc, $line);
                }
                fwrite($fmisc, "\n\n");
            }

            fclose($fimp);
            fclose($fbug);
            fclose($fmisc);

            //Create final output file
            $file = "release_notes_$repository" . "_$from_version" . "_to_" . "_$to_version.md";

            // push the file to global list if it should be used for meta
            if ($for_meta == true) {
                @array_push($GLOBALS["rn_list"], $file);
            }
            // Deletes the output file for the repo if it's only used to build meta
            if ($delete == true)
            {
                @array_push($GLOBALS["rn_list_to_delete"], $file);
            }


            $f = fopen($file, "a+");
            fwrite($f, "[$repository](https://github.com/ezsystems/$repository) changes between " .
            "[v$from_version](https://github.com/ezsystems/$repository/releases/tag/v$from_version) and " .
            "[v$to_version](https://github.com/ezsystems/$repository/releases/tag/v$to_version)\n\n");

            //Add improvements to the final file
            $imp_contents = file_get_contents($improvements_file);
            fwrite($f, $imp_contents);
            fwrite($f, "\n");

            //Add bugs to the final file
            $bug_contents = file_get_contents($bugs_file);
            fwrite($f, $bug_contents);
            fwrite($f, "\n");

            //Add misc to the final file
            $misc_contents = file_get_contents($misc_file);
            fwrite($f, $misc_contents);

            //Delete temporary files
            unlink($bugs_file);
            unlink($improvements_file);
            unlink($misc_file);

            fclose($f);

            print_r("Done.\n");
        }
    }
}