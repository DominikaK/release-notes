<?php

// Gets a file from GitHub
function get_from_github($file) {
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

function create_release_notes ($repo)
{
    $repository = $repo[0];
    $to_version = $repo[1];
    $from_version = $repo[2];

    print_r("\n" . $repository . ":\n");

    // Create compare file for the current repository
    $compare_file = "https://api.github.com/repos/ezsystems/$repository/compare/$from_version...$to_version";

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

            //Check if there is a jira project and ticket number
            $ticket = preg_match('/(((EZP)|(EZEE)|(DEMO))-[[:digit:]]+)/', $commit_name, $matches_ticket);
            //Check if there is a PR number
            $pr = preg_match('/((?<=[#])[[:digit:]]+)/', $commit_name, $matches_pr);

            // If there is no ticket in the commit message and there is a PR, look through PR description
            if ($ticket === 0 && $pr === 1) {
                $pr_output = json_decode(get_from_github("https://api.github.com/repos/ezsystems/$repository/pulls/$matches_pr[0]"));
                $pr_body = $pr_output->body;
                //Override ticket with the ticket info taken from PR description
                $ticket = preg_match('/(((EZP)|(EZEE)|(DEMO))-[[:digit:]]+)/', $pr_body, $matches_ticket);
            }

            //If there is a ticket in commit message or we got one from the PR
            if ($ticket === 1) {
                //Gets jira info for the current ticket
                $jira_file = "https://jira.ez.no/rest/api/2/issue/$matches_ticket[0]";

                //Converts api output to json
                $jira_json = @file_get_contents($jira_file);
                $jira_output = json_decode($jira_json);

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
            }
            // If issue is a Bug, place it in Bugs
            elseif ($key[1] === "Bug") {
                $bugs_list += [$ticket_out => $key];
            }
            // Else place it in improvements
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
        $file = "release_notes_$repository" . "_$to_version.md";
        @array_push($GLOBALS["rn_list"], $file);
        $f = fopen($file, "a+");
        fwrite($f, "$repository changes between $from_version and $to_version\n\n");

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