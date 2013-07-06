<?php

// Copyright 2013 The Regents of the University of California. All rights reserved.
// ================================================================================
//
//     Created by Jeffrey S. Grethe
//     Center for Research in Biological Systems
//     Open source software, licensed under the BSD License
//         http://opensource.org/licenses/BSD-3-Clause
//
// Redistribution and use in source and binary forms, with or without modification, are permitted provided that the
// following conditions are met:
//
// 1. Redistributions of source code must retain the above copyright notice, this list of conditions and the
// following disclaimer.
//
// 2. Redistributions in binary form must reproduce the above copyright notice, this list of conditions and the
// following disclaimer in the documentation and/or other materials provided with the distribution.
//
// 3. Neither the name of the University nor the names of its contributors may be used to endorse or promote products
// derived from this software without specific prior written permission.
//
// THIS SOFTWARE IS PROVIDED BY THE REGENTS AND CONTRIBUTORS ``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING,
// BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED.
// IN NO EVENT SHALL THE REGENTS AND CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY,
// OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
//     DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT,
// STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE,
// EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.

//Setup error and warning preferences
error_reporting(E_ALL ^ E_WARNING); 
$use_errors = libxml_use_internal_errors(true);

//Setup Source Information
//$sourceName = 'HarvardDataVerse';
//$sourceName = 'Dryad';
$sourceName = 'arXiv';
//$sourceName = 'LOC';

//$oaiURL = 'http://dvn.iq.harvard.edu/dvn/OAIHandler';
//$oaiURL = 'http://www.datadryad.org/oai/request';
$oaiURL = 'http://export.arxiv.org/oai2/request';
//$oaiURL = 'http://memory.loc.gov/cgi-bin/oai2_0';

//To be nice to OAI-PMH sites set a wait time for each request (e.g. arXiv suggests 20 seconds)
$sleepTime = 20;

//Set date to to allow updates
$dateTag = date("Y-m-d");

//Check on and identify of OAI-PMH services at target site
$url = $oaiURL . "?verb=Identify";
$xmlObj = simplexml_load_file($url);
	
//If no response from OAI-PMH server then exit		
if (!$xmlObj) {
	echo "[ERROR] Can not contact the OAI-PMH server at $url \n";
	exit(1);
}

//Collect repository information from returned XML
$repoName = $xmlObj->Identify->repositoryName;
echo "[STATUS] Contacted archive: $repoName \n";

//Setup archive directory
$cwd = getcwd();

$directory = $cwd . '/' . preg_replace('/\s+/', '', $repoName);

if (!mkdir($directory, 0755, true)) {
    echo "[ERROR] Failed to create repository folder $directory \n";
    exit (1);
}

//Setup file for repository information
$infoFile = $directory . '/' . preg_replace('/\s+/', '', $sourceName) . '_' . 'info' . '.xml';

// Create or revert (empty) file for new run
$fp = fopen($infoFile, 'w+');
fclose($fp);

//Output repository information to status file
file_put_contents($infoFile, "<?xml version=\"1.0\"?>\n", FILE_APPEND | LOCK_EX);
file_put_contents($infoFile, "<OAI-PMH>\n", FILE_APPEND | LOCK_EX);

file_put_contents($infoFile, $xmlObj->Identify->asXML(), FILE_APPEND | LOCK_EX);
file_put_contents($infoFile, "\n", FILE_APPEND | LOCK_EX);

file_put_contents($infoFile, "</OAI-PMH>\n", FILE_APPEND | LOCK_EX);

//Check that oai_dc is supported by the OAI-PMH services at target site
$metaFormat = 'oai_dc';
$formatOK = 0;

$url = $oaiURL . "?verb=ListMetadataFormats";
$xmlObj = simplexml_load_file($url);
	
//If no response from OAI-PMH server then exit			
if (!$xmlObj) {
	echo "[ERROR] Can not contact the OAI-PMH server at $url \n";
	exit(1);
}

//Loop through the returned metadata formats to verify that oai_dc is supported
$xmlNode = $xmlObj->ListMetadataFormats;

foreach ($xmlNode->metadataFormat as $metaNode) {

	if ($metaNode->metadataPrefix == $metaFormat) {
		$formatOK = 1;
	}
		
}

//If oai_dc is not supported then exit
if ($formatOK) {    
	echo "[STATUS] $repoName archive supports $metaFormat \n";
} else {
	echo "[ERROR] $repoName archive does not support $metaFormat \n";
	exit (0);
}

// ************************************************************
// Loop Through the various sets and collect their information
// ************************************************************

//Get a full list of available record sets from the target server to loop through
$sets = array();

$url = $oaiURL . "?verb=ListSets";
$xmlObj = simplexml_load_file($url);

$xmlNode = $xmlObj->ListSets;

//Write information about each set to information file
foreach ($xmlNode->set as $setNode) {

	array_push($sets, array( (string) $setNode->setSpec, (string) $setNode->setName));
		
}

//Setup master file (i.e. file to hold records from all sets) for writing
$masterFile = $directory . '/' . preg_replace('/\s+/', '', $sourceName) . '_' . 'complete' . '.xml';
// Create or revert (empty) file for new process
$fp = fopen($masterFile, 'w+');
fclose($fp);

// **********************************************************
// Loop Through the various sets and collect their records
// **********************************************************
$setCounter = 1;
$setCount = count($sets);

foreach ($sets as $currentSet) {
	$setTag = $currentSet[0];
	$setName = $currentSet[1];
	
	echo "\n***************************************************************** \n";
	echo "[STATUS] Begin Processing Set [$setCounter out of $setCount]: $setName ($setTag) \n\n";
	sleep (30);
	
	//Setup record counters to verify ingestion as it progresses
	$totalRecords = 0;
	$currentRecords = 0;

	//Construct base URL for fetching records
	$baseURL = $oaiURL . '?verb=ListRecords';

	//Setup appropriate parameters for the target server and current record set
	$initialParams = '&metadataPrefix=oai_dc&set=' . $setTag;

	//Setup appropirate parameters for the target server in case a resumption token is provided
	$resumptionBase = '&resumptionToken=';
	$resumptionToken = 'initial';

	//Setup record set specific data file 
	$xmlFile = $directory . '/' . preg_replace('/\s+/', '', $sourceName) . '_' . $setTag . '_' . $dateTag . '.xml';

	// Create or revert (empty) file for new process
	$fp = fopen($xmlFile, 'w+');
	fclose($fp);
	
	//Setup counter to track number of requests to download complete record set
	$fetchCounter = 1;

	//Write data to both the record set specific data file as well as the master file
	file_put_contents($xmlFile, "<?xml version=\"1.0\"?>\n", FILE_APPEND | LOCK_EX);
	file_put_contents($xmlFile, "<ListRecords>\n", FILE_APPEND | LOCK_EX);

	file_put_contents($masterFile, "<?xml version=\"1.0\"?>\n", FILE_APPEND | LOCK_EX);
	file_put_contents($masterFile, "<ListRecords>\n", FILE_APPEND | LOCK_EX);

	// Construct proper URL based on existence of a resumption token
	while ($resumptionToken != '') {
		
		if ($fetchCounter == 1) { 
			//First call to fetch records will never have a resumption token as a parameter
			$url = $baseURL . $initialParams;
			$resumptionToken = ''; //Clear resumption token on first pass
		} else {
			$url = $baseURL . $resumptionBase . $resumptionToken;
		}

		//Now fetch records from OAI-PMH server
		echo "[STATUS] URL $fetchCounter being processed: $url \n";
	
		$urlTry = 1;
	
		while ($urlTry > 0) {
			$xmlObj = simplexml_load_file($url);
			
			//If there is a problem with retrieving the data then wait (with an increasing wait period) and retry 5 times
			if (!$xmlObj) {
				if ($urlTry < 5) {
					$errorWait = $sleepTime * $urlTry; //Increase wait time based on number of retries
      				echo "\t[WARNING] Load ($urlTry) of XML from URL Failed.  Retrying in $errorWait seconds. \n";
      			
      				foreach(libxml_get_errors() as $error) {
        				echo "\t\t[WARNING] ", $error->message;
    				}
    			
      				sleep ($errorWait);
      				$urlTry = $urlTry + 1;
      			} else { //Could not get records after 5 tries so exit
      				echo "\t[ERROR] Load of XML from URL Failed $urlTry time. Exiting... \n";
      			
      				foreach(libxml_get_errors() as $error) {
        				echo "\t\t[ERROR]", $error->message;
    				}
    				echo "\n";
    			
    				unlink($masterFile);
      				exit(1);
      			}
			} else {
				$urlTry = 0;
			}
		}
	
		//Clean up any errors from trying to fetch the current URL
		libxml_clear_errors();
		libxml_use_internal_errors($use_errors);

		//Run through the result and write records to local file validating record count
		$xmlNode = $xmlObj->ListRecords;
		$currentRecords = count($xmlNode->children());
	
		$recordValidator = 0;
		foreach ($xmlNode->record as $recordNode) {

			//Add repository, setName, URL to header of OAI-PMH XML so that output XML has all information about request and results
			$recordNode->header->addChild('repository', $repoName);
			$recordNode->header->addChild('setName', $setName);
			$recordNode->header->addChild('fetchURL', urlencode($url));
			$recordNode->header->addChild('recNum', $recordValidator);
			
			//Generate record set file content
			file_put_contents($xmlFile, $recordNode->asXML(), FILE_APPEND | LOCK_EX);
			file_put_contents($xmlFile, "\n", FILE_APPEND | LOCK_EX);
		
			//Generate master file content
			file_put_contents($masterFile, $recordNode->asXML(), FILE_APPEND | LOCK_EX);
			file_put_contents($masterFile, "\n", FILE_APPEND | LOCK_EX);
			
			$recordValidator = $recordValidator + 1;
    	}
    
    	if (!$xmlNode->resumptionToken) { //No more results - continue to next set
    		$totalRecords = $totalRecords + $currentRecords;
			echo "\t[INFO] Records added: $currentRecords \t\t Total Records: $totalRecords \n";
		
    		echo "\t[STATUS] All records fetched ($totalRecords) - no resumption token. \n";
		
    	} else { //Resumption token is present - so loop back and fetch more records
			$resumptionToken = '';
			$resumptionToken = $xmlNode->resumptionToken;
			
			echo "\t[INFO] Resumption Token: $resumptionToken \n";
		
			$currentRecords = $currentRecords - 1;
			$totalRecords = $totalRecords + $currentRecords;
			echo "\t[INFO] Records added: $currentRecords \t\t Total Records: $totalRecords \n";
			
			if ($currentRecords == 0) { //If there is an error in fetching records then exit
				echo "\t[ERROR] No data loaded from last URL.  Exiting... \n";
				exit (1);
			}
    	}
    
    	if ($recordValidator != $currentRecords) { //If there is an error in fetching records then exit
    		echo "\t[ERROR] Number of elements imported does not match. Exiting... \n";
    		
    		unlink($masterFile);
      		exit (1);
    	}
    
    	$fetchCounter = $fetchCounter + 1; //Increment record request for complete record set

    	//Play nice with OAI-PMH servers
    	sleep($sleepTime);
    	
	} // Work on next record request within record set

	//Finalize record set XML file
	file_put_contents($xmlFile, "</ListRecords>\n", FILE_APPEND | LOCK_EX);

	$setCounter = $setCounter + 1; 
	
} // Work on next set

//Finalize master XML file
file_put_contents($masterFile, "</ListRecords>\n", FILE_APPEND | LOCK_EX);

exit (0);
?>
