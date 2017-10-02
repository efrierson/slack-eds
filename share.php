<?php
    header('Content-Type: application/json; charset=UTF-8');
    
    function slack_clean ($text) {
        $text = str_replace("&","&amp;",$text);
        $text = str_replace("<","&gt;",$text);
        $text = str_replace(">","&lt;",$text);
        return $text;
    }
    
    session_start();
    
    if (isset($_GET["clearsession"])) {
        unset($_SESSION["SessionToken"]);
    }
    
    function getAuthenticationToken($invalid = false) {
        $tokenFile =fopen("token.txt","r");
        while(!feof($tokenFile)){
            $authToken = rtrim(fgets($tokenFile),"\n");
            $timeout = fgets($tokenFile)-600;
            $timestamp = fgets($tokenFile);
        }
        fclose($tokenFile);
        if(time()-$timestamp>=$timeout){
            // Lock check.
            
            $configFile = fopen("config.txt","r");
            while(!feof($configFile)){
                $userid = rtrim(fgets($configFile),"\n");
                $password = rtrim(fgets($configFile),"\n");
            }
            fclose($configFile);

            $url = "https://eds-api.ebscohost.com/Authservice/rest/UIDAuth";
            
            $params =<<<BODY
<UIDAuthRequestMessage xmlns="http://www.ebscohost.com/services/public/AuthService/Response/2012/06/01">
    <UserId>{$userid}</UserId>
    <Password>{$password}</Password>
</UIDAuthRequestMessage>
BODY;
            
            // Set the content type to 'application/xml'. Important, otherwise cURL will use the usual POST content type.
            $headers = array(
                'Content-Type: application/xml',
                'Conent-Length: ' . strlen($params)
            );            

            $session = curl_init($url); 	                        // Open the Curl session
            curl_setopt($session, CURLOPT_HEADER, false); 	        // Don't return HTTP headers
            curl_setopt($session, CURLOPT_RETURNTRANSFER, true);        // Do return the contents of the call
            curl_setopt($session, CURLOPT_POSTFIELDS, $params);
            curl_setopt($session, CURLOPT_HTTPHEADER, $headers);

            $response = curl_exec($session); 	                        // Make the call
            curl_close($session);                                       // And close the session
            
            $xml = simplexml_load_string($response);
            
            $authToken = (string)$xml->AuthToken;
            $timeout = (string)$xml->AuthTimeout;
            $timestamp = time();
            
            $tokenFile = fopen("token.txt","w+");

            fwrite($tokenFile, $authToken."\n");
            fwrite($tokenFile, $timeout."\n");
            fwrite($tokenFile, $timestamp);
            fclose($tokenFile);
            return $authToken;
        }else{
            return $authToken;
        }
        fclose($lockFile);       
    }
    
    function getSessionToken ($invalid = false) {
        if ((isset($_SESSION["SessionToken"])) && ($invalid == false)) {
            return $_SESSION["SessionToken"];
        } else {
            $url = "http://eds-api.ebscohost.com/edsapi/rest/CreateSession?profile=eds_api&guest=n";
            $authToken = getAuthenticationToken();
            $headers = array(
                'x-authenticationToken: ' . $authToken
            );            

            $session = curl_init($url); 	                        // Open the Curl session
            curl_setopt($session, CURLOPT_HEADER, false); 	        // Don't return HTTP headers
            curl_setopt($session, CURLOPT_RETURNTRANSFER, true);        // Do return the contents of the call
            curl_setopt($session, CURLOPT_HTTPHEADER, $headers);
            $response = curl_exec($session); 	                        // Make the call
            curl_close($session);                                       // And close the session
            
            $xml = simplexml_load_string($response);
            
            if (isset($xml->SessionToken)) {
                $_SESSION["SessionToken"] = (string) $xml->SessionToken;
                return $_SESSION["SessionToken"];
            } else {
                unset($_SESSION["SessionToken"]);
                $err_code = (string) $xml->ErrorNumber;
                $err_description = (string) $xml->DetailedErrorDescription;
                $err_reason = (string) $xml->Reason;
                die("Error retrieving session token (Error Code ".$err_code.": ".$err_description." (".$err_reason.")");
            }            
        }
    }
    
    function executeSearch($searchterm,$action="") {
        $authToken = getAuthenticationToken();
        $sessionToken = getSessionToken();

        if ($action == "") {
            $searchterm = str_replace(",","\\,",$searchterm);
            $searchterm = str_replace(":","\\:",$searchterm);
        }
        // Formatting for Display
        if ($action == "") {
            $url = "http://eds-api.ebscohost.com/edsapi/rest/Search?query=".urlencode($searchterm)."&resultsperpage=5&view=detailed";
        } else {
            $params = array();
            parse_str($searchterm,$params);
            $params['action'] = $action;
            $paramsurl = http_build_query($params);
            $url = "http://eds-api.ebscohost.com/edsapi/rest/Search?".$paramsurl;
            echo "<p>Params: <pre>".var_export($params,TRUE)."</pre></p>";
        }

        $headers = array(
            'x-authenticationToken: ' . $authToken, 
            'x-sessionToken: ' . $sessionToken
        );
            
        $session = curl_init($url); 	                        // Open the Curl session
        curl_setopt($session, CURLOPT_HEADER, false); 	        // Don't return HTTP headers
        curl_setopt($session, CURLOPT_RETURNTRANSFER, true);        // Do return the contents of the call
        curl_setopt($session, CURLOPT_HTTPHEADER, $headers);
        $response = curl_exec($session); 	                        // Make the call
        curl_close($session); 

        //echo "<textarea>".$response."</textarea>";
        
        $xml = simplexml_load_string($response);
        
        if (isset($xml->ErrorNumber)) {
            if ((string)$xml->ErrorNumber == "109") {
                $sessionToken = getSessionToken(true);
                return executeSearch($searchterm);
            } else {
                echo "<h3>Error</h3>";
                echo "<p>The request was: <input type='text' name='request' value='".$url."' style='width:100%;' /></p>";
                echo "<h3>Response:</h3><textarea>".var_export($xml,TRUE)."</textarea>";
                die("Error number ".(string)$xml->ErrorNumber);
            }
        } else {
            $doc = new DOMDocument();
            $doc->loadXML($response);
            $doc->preserveWhiteSpace = false;
            $doc->formatOutput = true;
        
            $xml_string = $doc->saveXML();
            
            return $xml_string;            
        }
    }

    function executeResearch($an,$db) {
        $authToken = getAuthenticationToken();
        $sessionToken = getSessionToken();
        
        $url = "http://eds-api.ebscohost.com/edsapi/rest/Retrieve?an=".urlencode($an)."&dbid=".urlencode($db);
        //echo "Request: ".$url;

        $headers = array(
            'x-authenticationToken: ' . $authToken, 
            'x-sessionToken: ' . $sessionToken
        );
            
        $session = curl_init($url); 	                        // Open the Curl session
        curl_setopt($session, CURLOPT_HEADER, false); 	        // Don't return HTTP headers
        curl_setopt($session, CURLOPT_RETURNTRANSFER, true);        // Do return the contents of the call
        curl_setopt($session, CURLOPT_HTTPHEADER, $headers);
        $response = curl_exec($session); 	                        // Make the call
        curl_close($session); 

        $xml = simplexml_load_string($response);
        
        if (isset($xml->ErrorNumber)) {
            if ((string)$xml->ErrorNumber == "109") {
                $sessionToken = getSessionToken(true);
                return executeSearch($searchterm);
            } else {
                echo "<h3>Error</h3>";
                echo "<p>The request was: <input type='text' name='request' value='".$url."' style='width:100%;' /></p>";
                die("Error number ".(string)$xml->ErrorNumber);
            }
        } else {
            $doc = new DOMDocument();
            $doc->loadXML($response);
            $doc->preserveWhiteSpace = false;
            $doc->formatOutput = true;
        
            $xml_string = $doc->saveXML();
            
            return $xml_string;            
        }
    }
    
    $json_from_slack = $_POST['payload'];
    $json = json_decode($json_from_slack);
    $andb = explode("--@@--",(string)$json->callback_id);
    $an = $andb[0];
    $db = $andb[1];
    
    $channelid = $json->channel->id;
    $channelname = $json->channel->name;
    $userid = $json->user->id;
    $username = $json->user->name;
    $token = $json->token;
    
    $raw_response = executeResearch($an,$db);
    $xml = simplexml_load_string($raw_response);

    $final_response = array();
    $final_response['attachments'] = array();
                            
    $record = $xml->Record;
    $slack_response = "*@".$username."* found this ".strtolower((string)$record->Header->PubType).":\n\n";
    $abstract = '';
    $title = '';
    foreach ($record->Items->Item as $item) {
        if (strtolower((string)$item->Group) == "ti") {
            $title = html_entity_decode((string)$item->Data);
            $title = str_replace("<highlight>","*_",$title);
            $title = str_replace("</highlight>","_*",$title);
            $title = slack_clean(strip_tags($title));
            $slack_response .= "<". $record->PLink ."|" . $title . ">";    
        }
        if (strtolower((string)$item->Group) == "ab") {
            $abstract = html_entity_decode((string)$item->Data);
            $abstract = str_replace("<highlight>","*_",$abstract);
            $abstract = str_replace("</highlight>","_*",$abstract);
            $abstract = slack_clean(strip_tags($abstract));
        }
    }
    $pretext = (string)$record->Header->PubType;

    $attachments = array();
    $attachment = array();
    $attachment['title'] = $pretext;
    $attachment['text'] = $abstract;
    $attachment['pretext'] = $slack_response;
    $attachment['mrkdwn_in'] = array("text","pretext");

    array_push($attachments,$attachment);
    
    $attachment_json = json_encode($attachments);
    
    //echo "@".$username." (".$userid.") on #" . $channelname . " (".$channelid.") - ".$an." // ".$db."\n\nToken: ".$token;
    //echo json_encode($final_response);
    $url = "https://slack.com/api/chat.postMessage";
    
    $body = array();
    //$body['token'] = 'xoxb-249713768612-Hr90pRe3grrklFEdiGgAIMtW';
    $body['token'] = 'xoxp-238588943088-239377916277-249415594787-2b85baf75a2376ba2a87e2917445022c';
    //$body['token'] = $token;
    $body['channel'] = $channelid;
    //$body['text'] = "Should be attachments...";
    $body['attachments'] = $attachment_json;
    $body = http_build_query($body);
    
    $headers = array("Content-Type: application/x-www-form-urlencoded");
    
    $session = curl_init($url); 	                        // Open the Curl session
    curl_setopt($session, CURLOPT_HEADER, false); 	        // Don't return HTTP headers
    curl_setopt($session, CURLOPT_RETURNTRANSFER, true);        // Do return the contents of the call
    curl_setopt($session, CURLOPT_POSTFIELDS, $body);
    curl_setopt($session, CURLOPT_HTTPHEADER, $headers);

    $response = curl_exec($session); 	                        // Make the call
    curl_close($session);                                       // And close the session
    
    //echo "\n\nResponse:\n\n".$response;
?>