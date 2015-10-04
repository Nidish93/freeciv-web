<?php

/* do we want debug output to stderr?
 * This is very heavy so never leave it on in production
 */
$debug=0;

// include the php-code finder
ini_set("include_path", ini_get("include_path") . ":" . $_SERVER["DOCUMENT_ROOT"]);

include_once("php_code/settings.php");

if ($error_msg != NULL) {
  $config_problem = true;
}

if (! $config_problem) {
  include_once("php_code/php_code_find.php");
  // includes for support routines
  include_once(php_code_find("fcdb.php"));
  include_once(php_code_find("versions_file.php"));
  include_once(php_code_find("img.php"));
  include_once(php_code_find("html.php"));

  fcdb_metaserver_connect();
}

$fullself="http://".$_SERVER["SERVER_NAME"].$_SERVER["PHP_SELF"];

$posts = array(
  "host",
  "port",
  "bye",
  "version",
  "patches",
  "capability",
  "state",
  "ruleset",
  "message",
  "type",
  "serverid",
  "available",
  "humans",
  "vn",
  "vv",
  "plrs",
  "plt",
  "pll",
  "pln",
  "plf",
  "plu",
  "plh",
  "dropplrs",
  /* URL line cgi parameters */
  "server_port",
  "client",
  "client_cap",
  "rss"
);

/* This is where we store what variables we can collect from the server
 * If we want to add variables, they need to be here, and new columns
 * need to be added to the database. They will also be sent to the client */
$sqlvars = array(
  "version",
  "patches",
  "capability",
  "state",
  "ruleset",
  "message",
  "type",
  "available",
  "humans",
  "serverid"
);

/* this little block of code "changes" the namespace of the variables 
 * we got from the $_REQUEST variable to a local scope */
$assoc_array = array();
foreach($posts as $val) {
  if (isset($_REQUEST[$val])) {
    $assoc_array[$val] = $_REQUEST[$val];
  }
}
extract($assoc_array);


if ( isset($port) ) {
  /* All responses to the server will be text */
  header("Content-Type: text/plain; charset=\"utf-8\"");

  if ($_SERVER['SERVER_ADDR'] != $_SERVER['REMOTE_ADDR']){
    $this->output->set_status_header(400, 'No Remote Access Allowed');
    exit; //just for good measure
  }

  /* garbage port */
  if (!is_numeric($port) || $port < 1024 || $port > 65535) {
    print "exiting, garbage port \"$port\"\n";
    exit(1);
  }


  /* is this server going away? */
  if (isset($bye)) {
    $stmt="delete from servers where host=\"$host\" and port=\"$port\"";
    print "$stmt\n";
    $res = fcdb_exec($stmt);
    $stmt="delete from variables where hostport=\"$host:$port\"";
    print "$stmt\n";
    $res = fcdb_exec($stmt);
    $stmt="delete from players where hostport=\"$host:$port\"";
    print "$stmt\n";
    $res = fcdb_exec($stmt);
    print "Thanks, please come again!\n";
    exit(0); /* toss all entries and exit */
  }

  if (isset($message)) {
    $message = addneededslashes($message); /* escape stuff to go into the database */
  }
  if (isset($type)) {
    $type = addneededslashes($type); /* escape stuff going to database */
  }
  if (isset($serverid)) {
    $serverid = addneededslashes($serverid); /* escape stuff to go into the database */
  }


  /* lets get the player information arrays if we were given any */
  $playerstmt = array();
  if (isset($plu)) {
    for ($i = 0; $i < count($plu); $i++) { /* run through all the names */
      $ins = "insert into players set hostport=\"$host:$port\", ";

      if (isset($plu[$i]) ) {
        $plu[$i] = addneededslashes($plu[$i]);
        $ins .= "user=\"$plu[$i]\", ";
      }
      if (isset($pll[$i]) ) {
        $pll[$i] = addneededslashes($pll[$i]);
        $ins .= "name=\"$pll[$i]\", ";
      }
      if (isset($pln[$i]) ) {
        $pln[$i] = addneededslashes($pln[$i]);
        $ins .= "nation=\"$pln[$i]\", ";
      }
      if (isset($plf[$i]) ) {
        $plf[$i] = addneededslashes($plf[$i]);
        $ins .= "flag=\"$plf[$i]\", ";
      }

      if (isset($plt[$i]) ) {
        $plt[$i] = addneededslashes($plt[$i]);
        $ins .= "type=\"$plt[$i]\", ";
      }
      $ins .= "host=\"$plh[$i]\"";
      /* an array of all the sql statements; save actual db access to the end */
      debug("\nINS = $ins\n\n");
      array_push($playerstmt, $ins); 
    }
  }

  /* increment total turn count.  */
    $stmt = "select value from variables where name = 'turn' and hostport=\"$host:$port\"";
    $res = fcdb_exec($stmt);
    $nr = fcdb_num_rows($res);
    if ( $nr == 1 ) {
      $row = fcdb_fetch_array($res, 0);
      if ($row["value"] < $vv[8]) {
        $myincrease = intval($vv[8]) - intval($row["value"]);
        if ($myincrease > 0 && $myincrease <= 5) {
          $stmt="update turncount set count = count + " . addneededslashes($myincrease);
          $res = fcdb_exec($stmt);
        }
      }
    }

  /* delete this variables that this server might have already set. */
  $stmt="delete from variables where hostport=\"$host:$port\"";
  $res = fcdb_exec($stmt);

  /* lets get the variable arrays if we were given any */
  $variablestmt = array();
  if (isset($vn)) {
    for ($i = 0; $i < count($vn); $i++) { /* run through all the names */
      $vn[$i] = addneededslashes($vn[$i]);
      $vv[$i] = addneededslashes($vv[$i]);
      $ins = "insert into variables set hostport=\"$host:$port\", ";
      $ins .= "name=\"$vn[$i]\", ";
      $ins .= "value=\"$vv[$i]\"";
      /* an array of all the sql statements; save actual db access to the end */
      array_push($variablestmt, $ins);
    }
  }

  $stmt = "select * from servers where host=\"$host\" and port=\"$port\"";
  $res = fcdb_exec($stmt);

  /* do we already have an entry for this host:port combo? */
  if (fcdb_num_rows($res) == 1) {
    /* so this is an update */
    $string = array();
    $stmt = "update servers set ";

    /* iterate through the vars to build a list of things to update */
    foreach ($sqlvars as $var) {
      if (isset($assoc_array[$var])) {
        array_push($string, "$var=\"$assoc_array[$var]\"");
      }
    }

    /* we always want to update the timestamp */
    array_push($string, "stamp=now() ");

    $stmt .= join(", ", $string); /* put them all together */
    $stmt .= "where host=\"$host\" and port=\"$port\"";
  } else {
    /* so this is a brand new server and is an insert */
    $string = array();

    foreach($sqlvars as $var) {
      if (isset($assoc_array[$var])) {
        array_push($string, "$var=\"$assoc_array[$var]\"");
      }
    }

    /* we always want to update the timestamp */
    array_push($string, "stamp=now() ");

    $stmt = "insert into servers set host=\"$host\", port=\"$port\", ";
    $stmt .= join(", ", $string); /* put them all together */
  }

  print "$stmt\n"; /* server statement */

  /* Do all the processing above, we now hit the database */
  $res = fcdb_exec($stmt);

  /* Start the log entry by logginhg the statement it self. */
  debug("\nSTMT = $stmt");

  /* Log the result. */
  if ($res) {
    debug("\nResult: OK");
  } else {
    debug("\n$error_msg_stderr");
  }

  /* Finish the log entry. */
  debug("\n\n");

  for ($i = 0; $i < count($variablestmt); $i++) {
    print "$variablestmt[$i]\n";
    $res = fcdb_exec($variablestmt[$i]);
  }

  /* if we have a playerstmt array we want to zero out the players
   * and if the server wants to explicitly tell us to drop them all */
  if (count($playerstmt) > 0 || isset($dropplrs)) { 
    $delstmt = "delete from players where hostport=\"$host:$port\"";

    print "$delstmt\n";

    $res = fcdb_exec($delstmt);

    /* if dropplrs=1 then set available back to 0 */
    if (isset($dropplrs)) {
      $avstmt = "update servers set available=0, humans=-1 where host=\"$host\" and port=\"$port\"";
      $res = fcdb_exec($avstmt);
    }

    for ($i = 0; $i < count($playerstmt); $i++) {
      print "$playerstmt[$i]\n";
      $res = fcdb_exec($playerstmt[$i]);
    }
  }

  /* We've done the database so we're done */

} else {

  header("Content-Type: text/html; charset=\"utf-8\"");

?>

<!DOCTYPE html>
<html>
  <head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="author" content="The Freeciv project">
    <meta name="description" content="Freeciv is a Free and Open Source empire-building strategy game made with HTML5 which you can play in your browser, tablet or mobile device!">
    <meta name="google-site-verification" content="Dz5U0ImteDS6QJqksSs6Nq7opQXZaHLntcSUkshCF8I" />

    
    <title>Freeciv-web - strategy game playable online with HTML5</title>

    <link rel="shortcut icon" href="/images/freeciv-shortcut-icon.png">
    <link rel="apple-touch-icon" href="/images/freeciv-splash2.png" />

    <script type="text/javascript" src="/javascript/libs/jquery.min.js"></script>
    <script type="text/javascript" src="/javascript/libs/jquery-ui.min.js"></script>   
    <script type="text/javascript" src="/javascript/libs/raphael-min.js"></script>   
    <script type="text/javascript" src="/javascript/libs/morris.min.js"></script>   
    <script type="text/javascript" src="/meta/js/meta.js"></script>

    <!-- Bootstrap core CSS -->
    <link href="/css/bootstrap.min.css" rel="stylesheet">
    <link href="/css/morris.css" rel="stylesheet">

    <link type="text/css" href="/css/jquery-ui.min.css" rel="stylesheet" />

    <link href="/css/frontpage.css" rel="stylesheet">

    <link href="/meta/css/metaserver.css" rel="stylesheet">


<script>
   (function(i,s,o,g,r,a,m){i['GoogleAnalyticsObject']=r;i[r]=i[r]||function(){
  (i[r].q=i[r].q||[]).push(arguments)},i[r].l=1*new Date();a=s.createElement(o),
  m=s.getElementsByTagName(o)[0];a.async=1;a.src=g;m.parentNode.insertBefore(a,m)
  })(window,document,'script','//www.google-analytics.com/analytics.js','ga');

  ga('create', 'UA-40584174-1', 'auto');
  ga('send', 'pageview'); 
</script> 

  </head>


  <body>

    <script type="text/javascript" src="//dl1d2m8ri9v3j.cloudfront.net/releases/1.2.5/tracker.js" data-customer="ee5dba6fe2e048f79b422157b450947b"></script>

    <div class="container">


      <div class="masthead">
        <h3 class="text-muted">The Freeciv-Web Project</h3>
        <ul class="nav nav-justified">
          <li><a href="/">Play Freeciv!</a></li>
          <li><a href="http://play.freeciv.org/blog/">Blog</a></li>
          <li><a href="http://www.freeciv.org/wiki/">Wiki</a></li>
          <li class="active"><a href="/meta/metaserver.php">Live Games</a></li>
          <li><a href="http://forum.freeciv.org/f/viewforum.php?f=24">Forum</a></li>
          <li><a href="http://github.com/freeciv/freeciv-web">Development</a></li>
          <li><a href="http://freeciv.wikia.com/wiki/Donations">Donate</a></li>
        </ul>
      </div>

<div id="body_content">

 <div class="row span10 metaspan">


<?php

  if ($error_msg != NULL) {
    echo $error_msg;
  } else {
    if (isset($server_port)) {
 

      $port = substr(strrchr($server_port, ":"), 1);
      $host = substr($server_port, 0, strlen($server_port) - strlen($port) - 1);
      print "<div class='freeciv_game_info_box'><h1>Freeciv-web server id: " . db2html($port) . "</h1>\n";
      
      $stmt = "select * from servers where host=\"$host\" and port=\"$port\"";
      $res = fcdb_exec($stmt);
      $nr = fcdb_num_rows($res);
      if ( $nr != 1 ) {
        print "Cannot find the specified server";
      } else {
        $row = fcdb_fetch_array($res, 0);
  
        print "<br/><center>";
        $msg = db2html($row["message"]);
        if ($msg != "") {
          print "<p>";
          print "<table class='server_message'><tr class='meta_header'><th>Message</th></tr>\n";
          print "<tr><td class='message_box'>" . $msg . "</td></tr>";
          print "</table></p>\n";
        }
        if ($row["state"] == "Pregame") {
          print "<div><a class='button' href='/webclient/?action=multi&civserverport=" . db2html($port) . "&amp;civserverhost=" . db2html($host)
             . "'>Join</a> <b>You can join this game now.</b></div><br>";
	} else {
          print "<div><a class='button' href='/webclient?action=observe&amp;civserverport=" . db2html($port) . "&amp;civserverhost=" . db2html($host)
             . "'>Join/Observe</a> <b>You can observe this game now.</b></div><br>";
	}
        print "<table class='server_stats'><tr class='meta_header'><th>Version</th><th>Patches</th><th>Capabilities</th>";
        print "<th>State</th><th>Ruleset</th>";
        print "<th>Server ID</th></tr>\n";
        print "<tr class='meta_row'><td>";
        print db2html($row["version"]);
        print "</td><td>";
        print db2html($row["patches"]);
        print "</td><td>";
        print db2html($row["capability"]);
        print "</td><td>";
        print db2html($row["state"]);
        print "</td><td>";
        print db2html($row["ruleset"]);
        print "</td><td>";
        print db2html($row["serverid"]);
        print "</td></tr>\n</table></p>\n";
          $stmt="select * from players where hostport=\"$server_port\" order by name";
        $res = fcdb_exec($stmt);
        $nr = fcdb_num_rows($res);
        if ( $nr > 0 ) {
          print "<p><div><table class='metainfotable'>\n";
          print "<tr class='meta_header'><th class=\"left\">Flag</th><th>Leader</th><th>Nation</th>";
          print "<th>User</th><th>Type</th></tr>\n";
          for ( $inx = 0; $inx < $nr; $inx++ ) {
            $prow = fcdb_fetch_array($res, $inx);
            print "<tr class='meta_row'><td class=\"left\">";
	    flag_html("f." . $prow["flag"]);
            print "</td><td>";
            print db2html($prow["name"]);
            print "</td><td>";
            print db2html($prow["nation"]);
            print "</td><td>";
            print db2html($prow["user"]);
            print "</td><td>";
            print db2html($prow["type"]);
            print "</td></tr>\n";
          }
          print "</table></div><p>\n";
        } else {
          print "<p>No players</p>\n";
        }
        if ($row["state"] == "Running") {
          print("<br><b>Scores:</b><div id='scores'></div><br><br><b>Settings:</b><br>");
          print("<script type='text/javascript'>show_scores(" . $port . ")</script>");
        }
        $stmt="select * from variables where hostport=\"$server_port\"";
        $res = fcdb_exec($stmt);
        $nr = fcdb_num_rows($res);
        if ( $nr > 0 ) {
          print "<table class='variables_table'>\n";
          print "<tr><th class=\"left\">Option</th><th>Value</th></tr>\n";
          for ( $inx = 0; $inx < $nr; $inx++ ) {
            $row = fcdb_fetch_array($res, $inx);
            print "<tr><td>";
            print db2html($row["name"]);
            print "</td><td>";
            print db2html($row["value"]);
            print "</td></tr>\n";
          }
          print "</table></center>";
          print "<P><a class='button' href=\"".$_SERVER["PHP_SELF"]."\">Return to games list</a></div>";
        }

      }
    } else {

      $stmt="select count(*) as count from servers s where type = 'singleplayer' and state = 'Running'";
      $res = fcdb_exec($stmt);
      $row = fcdb_fetch_array($res, 0);
      $single_count = $row["count"];
      $stmt="select count(*) as count from servers s where type = 'multiplayer' and (state = 'Running' or (state = 'Pregame' and CONCAT(s.host ,':',s.port) in (select hostport from players where type <> 'A.I.')))";
      $res = fcdb_exec($stmt);
      $row = fcdb_fetch_array($res, 0);
      $multi_count = $row["count"];
	?>

<div id="tabs">
<ul>
<li><a id="singleplr" href="#tabs-1">Single-player Games (<? print $single_count ?>)</a></li>
<li><a id="multiplr" href="#tabs-2">Multi-player Games (<?  print $multi_count ?>)</a></li>
<li><a id="freecivmeta" href="#tabs-3">Desktop Games</a></li>
</ul>
<div id="tabs-1">
<h2>Freeciv-web Single-player games</h2>
<?
      $stmt="select host,port,version,patches,state,message,unix_timestamp()-unix_timestamp(stamp), IFNULL((select user from players p where p.hostport =  CONCAT(s.host ,':',s.port) and p.type = 'Human' Limit 1 ), 'none') as player, IFNULL((select flag from players p where p.hostport =  CONCAT(s.host ,':',s.port) and p.type = 'Human' Limit 1 ), 'none') as flag, (select value from variables where name = 'turn' and hostport = CONCAT(s.host ,':',s.port)) as turn, (select value from variables where name = 'turn' and hostport = CONCAT(s.host ,':',s.port)) + 0 as turnsort from servers s where type = 'singleplayer' and state = 'Running' order by turnsort desc";
      $res = fcdb_exec($stmt);
      $nr = fcdb_num_rows($res);
      if ( $nr > 0 ) {
        print "<br /><table class='metatable singleplayer'>\n";
        print "<tr class='meta_header'><th>Game Action:</th>";
        print "<th>Players</th>";
        print "<th style='width:45%;'>Message</th>";
        print "<th>Player</th>\n";
        print "<th>Flag</th>\n";
        print "<th>Turn:</th></tr>";
        for ( $inx = 0; $inx < $nr; $inx++ ) {
          $row = fcdb_fetch_array($res, $inx);
          if (strpos($row["message"],'password-protected') !== false) {  
            print "<tr class='meta_row private_game'><td>";
          } else {
            print "<tr class='meta_row'><td>";
          }
	  print "<a  class='button' href=\"/webclient?action=observe&amp;civserverport=" 
		  . db2html($row["port"]) . "&amp;civserverhost=" . db2html($row["host"]) . "\">";
          print "Observe";
          print "</a>";

          print "<a class='button' href=\"/meta/metaserver.php?server_port=" . db2html($row["host"]) . ":" . db2html($row["port"]) . "\">";
	  	  print "Info";
          print "</a>";
	  print "</td><td>";
          $stmt="select * from players where hostport=\"".$row['host'].":".$row['port']."\"";
          $res1 = fcdb_exec($stmt);
          print fcdb_num_rows($res1);
          print "</td><td style=\"width: 30%\" >";
          print db2html($row["message"]);
          print "</td><td>";

          print db2html($row["player"]);
	  print "</td><td>"
	  flag_html("f." . $row["flag"]);
	  print "</td><td>"
          print db2html($row["turn"]);
	  print "</td></tr>\n";
        }
        print "</table><br><br><br>";
      } else {
        print "<h3><a href='/webclient/?action=new'>Click here to start a new single player game!</a></h3><br><br><br>";
      }
?>

 </div>
 <div id="tabs-2">
 <h2>Freeciv-web Multiplayer games around the world</h2>

<?
      $stmt="select host,port,version,patches,state,message,unix_timestamp()-unix_timestamp(stamp), (select value from variables where name = 'turn' and hostport = CONCAT(s.host ,':',s.port)) as turn from servers s where type = 'multiplayer' order by state desc";
      $res = fcdb_exec($stmt);
      $nr = fcdb_num_rows($res);
      if ( $nr > 0 ) {
	print "<table class='metatable multiplayer'>\n";
        print "<tr class='meta_header'><th>Game Action:</th>";
        print "<th>State</th><th>Players</th>";
        print "<th style='width:45%;'>Message</th>";
        print "<th>Turn:</th></tr>";

        for ( $inx = 0; $inx < $nr; $inx++ ) {
 	  $row = fcdb_fetch_array($res, $inx);
	  $mystate = db2html($row["state"]);

          $stmt="select * from players where type='Human' and hostport=\"".$row['host'].":".$row['port']."\"";
	  $res1 = fcdb_exec($stmt);

	  print "<tr class='meta_row ";
	  if (strpos($row["message"],'password-protected') !== false) {  
            print " private_game ";
          } else if ($mystate == "Running") {
            print " running_game ";
	  } else if (fcdb_num_rows($res1) != 0) {
	    print " pregame_with_players ";
	  }
	  print "'><td> "; 

          if ($mystate != "Running") {
           print "<a  class='button' href=\"/webclient?action=multi&civserverport=" . db2html($row["port"]) . "&amp;civserverhost=" . db2html($row["host"]) . "&amp;multi=true\">";
           print "Play";
	   print "</a>";
	  } else {
	   print "<a  class='button' href=\"/webclient?action=observe&amp;civserverport=" . db2html($row["port"]) . "&amp;civserverhost=" . db2html($row["host"]) . "&amp;multi=true\">";
           print "Join/Observe";
           print "</a>";
	  }


          print "<a class='button' href=\"/meta/metaserver.php?server_port=" . db2html($row["host"]) . ":" . db2html($row["port"]) . "\">";
	  	  print "Info";
          print "</a>";

	  print "</td>";
	  print "<td>";

          print db2html($row["state"]);
          print "</td>";
	  if (fcdb_num_rows($res1) == 0) {
		  print ("<td>None" );
	  } else if (fcdb_num_rows($res1) == 1) {
		  print ("<td>" . fcdb_num_rows($res1 ) . " player");
	  } else {
		  print ("<td>" . fcdb_num_rows($res1 ) . " players");
	  }
          print "</td><td style=\"width: 30%\" >";
          print db2html($row["message"]);
	  print "</td><td>"
          print db2html($row["turn"]);
	  print "</td></tr>\n";


        }
        print "</table> </div> ";
      } else {
        print "<h2>No servers currently listed</h2>";
      }

?>


<div id="tabs-3">
  <b>Public servers for desktop Freeciv clients hosted on <a href="http://meta.freeciv.org">meta.freeciv.org</a></b>:
  <? include 'freeciv_org_metaserver.html'; ?>

</div>

</div>



</div>



<?
    }
    
    
  }
 ?>


</div>

      <!-- Site footer -->
      <div class="footer">
        <p>&copy; The Freeciv Project 2013-<script type="text/javascript">document.write(new Date().getFullYear());</script>. Freeciv-web is is free and open source software. The Freeciv C server is released under the GNU General Public License, while the Freeciv-web client is released under the GNU Affero General Public License.</p>


      </div>

    </div> <!-- /container -->


  </body>
  
  </html>



<?php 
} 

/* This returns a list of the capabilities that are mandatory in a given capstring
 * i.e. those that begin with a + 
 */
function mandatory_capabilities($capstr) {
  $return=array();
  $elements=preg_split("/\s+/",$capstr);
  foreach ($elements as $element) {
    if ( preg_match("/^\+/", $element) ) {
      array_push($return, ltrim($element,"+"));
    }
  }
  return($return);
}

/* This returns true if a cap is contained in capstr
 */
function has_capability($cap,$capstr) {
  $elements=preg_split("/\s+/",$capstr);
  foreach ($elements as $element) {
    $element=ltrim($element,"+"); /*drop + if there, because it wont match with it*/
    // debug("  comparing \"$cap\" to \"$element\"\n");
    if ( $cap == $element) {
      return(TRUE);
    } 
  }
  return(FALSE);
}

/* This returns true if all caps are contained in capstr
 */
function has_all_capabilities($caps,$capstr) {
  foreach ($caps as $cap) {
    if ( ! has_capability($cap,$capstr) ) {
      return(FALSE);
    }
  }
  return(TRUE);
}

function debug($output) {
  global $debug;
  if ( $debug ) {
    $stderr=fopen("php://stderr","a");
    fputs($stderr, $output);
    fclose($stderr);
  }
}
      
?>
