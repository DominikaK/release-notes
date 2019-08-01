<?php

include 'release_notes_util.php';

if (!$argv[3]) {
    exit("Please provide the repository name, to and from version.");
}

$token = '';

// Get Github auth token from file
if (file_exists('token.txt')) {
    $tokenFile = "token.txt";
    $tokenFile_content = file($tokenFile);
    // Assign Github auth token to $token
    $GLOBALS["token"] = $tokenFile_content[0];
} else {
    exit("Please provide your GitHub authentication token in a 'token.txt' file in this folder.");
}

$repo = [];

$repo[0] = $argv[1];
$repo[1] = strip_version($argv[2]);
$repo[2] = strip_version($argv[3]);
$repo[3] = "";

create_release_notes($repo);


// Print out JIRA tickets the script couldn't get info for, if there are any
if (!empty($failed_tickets))
{
    print_r("Could not get information for the following tickets: \n");
    foreach ($failed_tickets as $repository=>$ticket)
    {
        print_r($repository . ": " . $ticket . "\n");
    }

}