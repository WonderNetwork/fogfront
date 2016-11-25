<?php
use GuzzleHttp\Client;
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7;

require('./vendor/autoload.php');
function getAuthorFrontID($id) {
	/**
	###############################################################
	FILL THIS IN
    ###############################################################
	**/
	$people = [
	['id' => '1', 'name' => 'CLOSED', 'email' => '', 'frontID' => ''],

	];
	foreach($people as $p) {

		if ($p['id'] == $id) {
			return $p['frontID'];
		}
	}
}


/**
	###############################################################
	FILL THIS IN
	###############################################################
**/
$mysqli = new mysqli("192.168.2.1", "steve", "steveholt", "fogbugz");

$emailName = "Example Support";
$emailEmail = "support@example.com";
$emailnotFound = "notfound@example.com";
$inboxID = "inb_xxxxx";

/**
Good work, now go replace ##TOKEN## with your token in two places
**/


$query = "Select *, UNIX_TIMESTAMP(`dt`) as `dtt` from bugevent inner join `bug` on `bugevent`.`ixbug` = `bug`.`ixbug` where `bugevent`.`ixbug` and `sVerb` IN ('Incoming Email', 'Replied', 'Reopened', 'Resolved', 'Edited', 'Assigned', 'Closed', 'Reactivated','Emailed') and `bugevent`.`ixbug` >= 1443 order by `bugevent`.`ixBug` asc, `ixBugEvent` asc;";
$rs = $mysqli->query($query);


$client = new Client([
    'timeout'  => 6.0,
    'headers' => ["Authorization" => "Bearer ##TOKEN##",
    "Accept" => "application/json"]
]);



$later = [];
$private = [ 'Reopened', 'Resolved', 'Edited', 'Assigned', 'Closed', 'Reactivated'];
$append = 'j';
foreach($rs as $row) {

	//var_dump($row);
	echo "------------{$row['ixBug']}-{$row['ixBugEvent']}---------------\n";
	if (strlen(trim($row['s'])) == 0) {
		echo "Skipping {$row['sVerb']}, no content\n";
		continue;
	}
	if (in_array($row['sVerb'], $private)) {
		$later[] = $row;
		echo "Private, saving for the end\n";
	}
	if ($row['fExternal']) {
		$emailInfo = emailToArray($row['sCustomerEmail']);
		$author = null;
	}else {
		$emailInfo['name'] = $emailName;
		$emailInfo['email'] = $emailEmail;
		$author = getAuthorFrontID($row['ixPerson']);
	}
	echo "Sending to Front!\n";
	if (strlen(trim($emailInfo['email'])) == 0)
	{
		$emailInfo['email'] = $emailnotFound;
	}

	$format = 'markdown';
	if (strpos($row['sHTML'], "HTML:true") !== false) {
		$format = 'html';
	}

	$data = [
		'sender' => [
			'handle' => $emailInfo['email'],
			'source' => 'email',
			'name' => $emailInfo['name'],
			'author_id' => $author
			],
		'to' => ['paul@preinheimer.com'],
		'subject' => $row['sTitle'] . "({$row['ixBug']})",
		'body' => headerStrip($row['sHTML']),
		'body_format' => $format,
		'external_id' => $row['ixBugEvent'] . $append,
		'created_at' => (float) $row['dtt'],
		'type' => 'email',
		'metadata' => [
			'thread_ref' => $row['ixBug'] . $append,
			'is_inbound' => (bool) $row['fExternal'],
		]
	];

	try {
		$response = $client->post("https://api2.frontapp.com/inboxes/{$inboxID}/imported_messages",
		['json' =>$data]);
	} catch (GuzzleHttp\Exception\ClientException $e) {
		echo "Caught";
		var_dump($data);
		echo Psr7\str($e->getRequest());
		echo Psr7\str($e->getResponse());
	}

	throttle($response->getHeader('X-RateLimit-Remaining'));

}
echo "Sleeping\n";
sleep(5);
echo "Getting Ticket List\n";
$tickets = getTicketList($client);

echo "Processing Later records\n";
foreach ($later as $row) {
	$id = findTicket($row['ixBug'], $tickets);
	$author = getAuthorFrontID($row['ixPerson']);
	$data = [
		'body' => trim($row['s']),
		'author_id' => $author
	];
	try {
		$response = $client->post("https://api2.frontapp.com/conversations/{$id}/comments",
		['json' =>$data]);
		echo "Sending comment to {$id}\n";
	} catch (GuzzleHttp\Exception\ClientException $e) {
		echo "Caught";
		var_dump($data);
		echo Psr7\str($e->getRequest());
		echo Psr7\str($e->getResponse());
	}
	throttle($response->getHeader('X-RateLimit-Remaining'));

}
function throttle($header) {
	$remaining = $header[0];
	if ($remaining % 10 == 0) {
		echo "Requests Remaining: " . $remaining."\n";
	}

	if ($remaining < 20)
	{
		echo "Sleeping for 5, low on calls\n";
		sleep(5);
	}
	if ($remaining < 5)
	{
		$reset = $response->getHeader('X-RateLimit-Reset');
		$sleep = time() - $reset;
		$time += 4;
		echo "Sleeping for $time, rate limit approaching\n";
		sleep($time);
	}
}

function getTicketList($client) {
	$url = "https://api2.frontapp.com/conversations";
	$tickets = [];
	do {
		echo $url . "\n";
		try {
			$response = $client->get($url);
		} catch (GuzzleHttp\Exception\ClientException $e) {
			echo "Caught";
			var_dump($data);
			echo Psr7\str($e->getRequest());
			echo Psr7\str($e->getResponse());
		}
		$resp = json_decode($response->getBody(), true);
		throttle($response->getHeader('X-RateLimit-Remaining'));

		foreach($resp['_results'] as $r) {
			echo "{$r['id']} -> {$r['subject']} - {$r['created_at']}\n";
			$tickets[$r['id']] = ['subject' => $r['subject'], 'object' => $r];
		}
		if (isset($resp['_pagination']['next']) && strlen($resp['_pagination']['next']) > 0 && $resp['_pagination']['next'] != $url) {
			$url = $resp['_pagination']['next'];
		}else {
			break;
		}
	}while(1);
	return $tickets;
}

function findTicket($query, $ticketList) {
	foreach ($ticketList as $id => $data) {
		if (strpos($data['subject'], "({$query})")) {
			return $id;
		}
	}
	return null;
}


function emailToArray($email) {
	if (strpos($email, "<") === false ){
		return ['name' => $email, 'email' => $email];
	}
	$a = preg_match('!"?([^<"]+)"?\s?<([^>]+)>!', $email, $matches);
	return ['name' => $matches[2], 'email' => $matches[1]];

}

function addComment($conversation_id, $author_id, $body) {
	$client = new Client([
	    'timeout'  => 6.0,
	    'headers' => ["Authorization" => "Bearer ##TOKEN##",
	    "Accept" => "application/json"]
	]);


	$data = [
	'author_id' => $author_id,
	'body' => $body
	];

	try {
		$response = $client->post('https://api2.frontapp.com/conversations/{$conversation_id}/comments',
			['json' =>$data]
		);
	} catch (GuzzleHttp\Exception\ClientException $e) {
		echo "Caught";
		var_dump($data);
		echo Psr7\str($e->getRequest());
		echo Psr7\str($e->getResponse());
	}

}

function headerStrip($message) {
	$lines = explode("\n", $message);
	foreach($lines as $k => $line) {
		if (strlen(trim($line)) == 0)
		{
			unset($lines[$k]);
			break;
		}
		unset($lines[$k]);
	}
	$message = implode("\n", $lines);

	return $message;
}


function getInboxList($client) {
	$response = $client->get('https://api2.frontapp.com/inboxes');
	$inboxes = json_decode($response->getBody(),true);

	foreach($inboxes['_results'] as $i) {

		echo "[{$i['id']}] - {$i['name']}<br>\n";
	}
}

function getTeamList($client) {
	$response = $client->get('https://api2.frontapp.com/teammates');
	$teammates = json_decode($response->getBody(), true);
	//var_dump($teammates);

	foreach($teammates['_results'] as $team) {
		echo "{$team['id']} - {$team['email']}\n";
	}
}
