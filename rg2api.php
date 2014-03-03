<?php
  error_reporting(E_ALL);
 
  require_once( dirname(__FILE__) . '/rg2-config.php' );
  // override allows testing of a local configuration such as c:/xampp/htdocs/rg2
  if (file_exists(dirname(__FILE__) . '/rg2-override-config.php')) {
     require_once ( dirname(__FILE__) . '/rg2-override-config.php');
  }
  
  if (defined('OVERRIDE_KARTAT_DIRECTORY')) {
    $url = OVERRIDE_KARTAT_DIRECTORY;
  } else {
    $url = "../kartat/";
  }
  
  define('KARTAT_DIRECTORY', $url);
  define ('LOCK_DIRECTORY', dirname(__FILE__)."/lock/saving/");
  define('GPS_RESULT_OFFSET', 50000);
  define('GPS_INTERVAL', 3);
  
  if (isset($_GET['type'])) {
    $type = $_GET['type'];
  } else {
    $type = 'unknown';
  }
  if (isset($_GET['id'])) {
    $id = $_GET['id'];
  } else {
   $id = 0;
  } 

  if ($_SERVER['REQUEST_METHOD'] == 'GET') {
    handleGetRequest($type, $id);
  } else if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if ($type == 'uploadmapfile') {
      uploadMapFile();
    } else {
      handlePostRequest($type, $id);
    }
  } else {
    header('HTTP/1.1 405 Method Not Allowed');
    header('Allow: GET, POST');
  }


//
// Handle the encoding for input data if
// input encoding is not set to UTF-8
//
function encode_rg_input($input_str) {
  //
  $encoded = '';
  //
  if ( RG_INPUT_ENCODING != 'UTF-8' ) {
    //
    $encoded = @iconv( RG_INPUT_ENCODING, RG_OUTPUT_ENCODING, $input_str);
  } else {
    //
    $encoded = $input_str;
  }
  //
  if ( !$encoded ) {
    $encoded = "";
  }
  //
  return $encoded;
}

//
// Handle the encondig for output data if
// output encoding is not set to UTF-8
//

function encode_rg_output($output_str) {
  //
  $encoded = '';
  //
  if ( RG_INPUT_ENCODING != 'UTF-8' ) {
    //
    $encoded = @iconv( RG_OUTPUT_ENCODING, RG_INPUT_ENCODING, $output_str);
  } else {
    //
    $encoded = $output_str;
  }
  //
  if ( !$encoded ) {
    $encoded = "";
  }
  //
  return $encoded;
}

function uploadMapFile() {
  $write = array();
  $write["ok"] = FALSE;
  $write["status_msg"] = "Map upload failed.";
  $data = new stdClass();
  $data->x = $_POST["x"];
  $data->y = $_POST["y"];
  if (!logIn($data)) {
    $write["status_msg"] = "Login failed.";
  } else {       
    $filename = $_POST["name"];
    // PHP changes . and space to _ just for fun
    $filename = str_replace(".", "_", $filename);
    $filename = str_replace(" ", "_", $filename);
    if (is_uploaded_file($_FILES[$filename]['tmp_name'])) {
      $file = $_FILES[$filename];
      if ($file['type'] == 'image/jpeg') {
        if (move_uploaded_file($file['tmp_name'], KARTAT_DIRECTORY."temp.jpg")) {
            $write["ok"] = TRUE;
            $write["status_msg"] = "Map uploaded.";
        }
      }
    }
  }
  
  $keksi = generateNewKeksi();
  $write["keksi"] = $keksi;
  
  header("Content-type: application/json"); 
  echo json_encode($write);
}
  
function handlePostRequest($type, $eventid) {
  $data = json_decode(file_get_contents('php://input')); 
  $write = array();
  if (lockDatabase() !== FALSE) {
    if ($type != 'addroute') {
      $loggedIn = logIn($data);
    } else {
      // don't need to log in to add a route 
      $loggedIn = TRUE;
    }
    if ($loggedIn) {
      //rg2log($type);
      switch ($type) {  
      case 'addroute':
        $write = addNewRoute($eventid, $data);
        break; 

      case 'addmap':
        $write = addNewMap($data);
        break;     
 
      case 'editevent':
        $write = editEvent($eventid, $data);
        break; 

      case 'deleteevent':
        $write = deleteEvent($eventid);
       break; 

      case 'deleteroute':
        $write = deleteRoute($eventid);
        break; 
        
      case 'deletecourse':
        $write = deleteCourse($eventid);
        break; 
      
      case 'login':
        // handled by default before we got here
        $write["ok"] = TRUE;
        $write["status_msg"] = "Login successful";        
        break;
      
      default:
        $write["status_msg"] = "Request not recognised: ".$type;
        $write["ok"] = FALSE;
        break;
      } 
    } else {
      $write["ok"] = FALSE;
      $write["status_msg"] = "Incorrect user name or password";
    }
    unlockDatabase();
  } else {
    $write["status_msg"] = "File lock error";
    $write["ok"] = FALSE;
  } 
  
  $keksi = generateNewKeksi();
  $write["keksi"] = $keksi;
  
  header("Content-type: application/json"); 
  echo json_encode($write);
}

function logIn ($data) {
  if (isset($data->x) && isset($data->y)) {  
    $userdetails = extractString($data->x);
    $cookie = $data->y;
  } else {
    $userdetails = "anon";
    $cookie = "none";
  }
  $ok = TRUE;
  $keksi = trim(file_get_contents(KARTAT_DIRECTORY."keksi.txt"));
  //rg2log("logIn ".$userdetails." ".$cookie);
  if (file_exists(KARTAT_DIRECTORY."rg2userinfo.txt")) {
    $saved_user = trim(file_get_contents(KARTAT_DIRECTORY."rg2userinfo.txt"));
    $temp = crypt($userdetails, $saved_user);
    if ($temp != $saved_user) {
      rg2log("User details incorrect. ".$temp." : ".$saved_user);
      $ok = FALSE;
    }
    if ($keksi != $cookie) {
      rg2log("Cookies don't match. ".$keksi." : ".$cookie);
      $ok = FALSE;
    }
  } else {
    // new account being set up: rely on JS end to force a reasonable name/password
    $temp = crypt($userdetails, $keksi);
    //rg2log("Creating new account ".$temp);
    file_put_contents(KARTAT_DIRECTORY."rg2userinfo.txt", $temp.PHP_EOL);  
  }
  return $ok;
}

// Mickey Mouse function to extract user name and password
// just avoids plain text transmission for now so a bit better than RG1
function extractString($data) {
  $str = "";
  for ($i = 0; $i < strlen($data); $i = $i + 2) {
    $str .= substr($data, $i, 1);
  }
  return $str;
}

function generateNewKeksi() {
  // simple cookie generator! Don't need unique, just need something vaguely random
  $keksi = substr(str_shuffle("abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890"), 0, 20);
  file_put_contents(KARTAT_DIRECTORY."keksi.txt", $keksi.PHP_EOL);
  //rg2log("Writing keksi.txt ".$keksi);
  return $keksi; 
}

function editEvent($eventid, $newdata) {
  $write["status_msg"] = "";
  $updatedfile = array();
  $oldfile = file(KARTAT_DIRECTORY."kisat.txt");
  foreach ($oldfile as $row) {
    $data = explode("|", $row);
    if ($data[0] == $eventid) {
      //rg2log($eventid);
      $data[3] = $newdata->name;
      $data[4] = $newdata->eventdate;
      $data[5] = $newdata->club;
      $data[6] = $newdata->type;
      $data[7] = $newdata->comments;
      $row = "";
      // reconstruct |-separated row
      for ($i = 0; $i < count($data); $i++) {
        if ($i > 0) {
          $row .= "|";
        }
        $row .= $data[$i];  
      }
      $row .= PHP_EOL;
      //rg2log($row);
    }
    $updatedfile[] = $row;
  }
  $status = file_put_contents(KARTAT_DIRECTORY."kisat.txt", $updatedfile);   
  
  if (!$status) {
    $write["status_msg"] .= " Save error for kisat.txt.";
  }
  if ($write["status_msg"] == "") {
    $write["ok"] = TRUE;
    $write["status_msg"] = "Event detail updated";
    rg2log("Event updated|".$eventid);
  } else {
    $write["ok"] = FALSE;    
  }
  
  return($write);
}

function deleteEvent($eventid) {
  $write["status_msg"] = "";
  $updatedfile = array();
  $oldfile = file(KARTAT_DIRECTORY."kisat.txt");
  foreach ($oldfile as $row) {
    $data = explode("|", $row);
    if ($data[0] != $eventid) {
      $updatedfile[] = $row;
    }
  }
  $status = file_put_contents(KARTAT_DIRECTORY."kisat.txt", $updatedfile);   
  
  if (!$status) {
    $write["status_msg"] .= " Save error for kisat.";
  }
  
  // rename all associated files but don't worry about errors
  // safer than deleting them since you can alwsy add the event again
  $files = array("kilpailijat_", "kommentit_", "merkinnat_", "radat_", "ratapisteet_", "sarjat_", "sarjojenkoodit_");
  foreach ($files as $file) {
    @rename(KARTAT_DIRECTORY.$file.$eventid.".txt", KARTAT_DIRECTORY."deleted_".$file.$eventid.".txt");
  }
              
  if ($write["status_msg"] == "") {
    $write["ok"] = TRUE;
    $write["status_msg"] = "Event deleted";
    rg2log("Event deleted|".$eventid);
  } else {
    $write["ok"] = FALSE;    
  }
  
  return($write);
}

function deleteCourse($eventid) {
  $write["status_msg"] = "";
  if (isset($_GET['courseid'])) {
    $courseid = $_GET['courseid'];
    // delete comments
    $filename = KARTAT_DIRECTORY."kommentit_".$eventid.".txt";
    $oldfile = file($filename);
    $updatedfile = array();
    $deleted = FALSE;
    foreach ($oldfile as $row) {
      $data = explode("|", $row);
      if ($data[0] == $courseid) {
        $deleted = TRUE;
      } else {
        $updatedfile[] = $row;
      }
    }
    $status = file_put_contents($filename, $updatedfile);
    
    if (!$status) {
      $write["status_msg"] .= "Save error for kommentit. ";
    }

    // delete result records
    $filename = KARTAT_DIRECTORY."kilpailijat_".$eventid.".txt";
    $oldfile = file($filename);
    $updatedfile = array();
    $deleted = FALSE;
    foreach ($oldfile as $row) {
      $data = explode("|", $row);
      $deleted = FALSE;
      if ($data[1] == $courseid) {
        $deleted = TRUE;                    
      } else {
        $updatedfile[] = $row;
      }
    }
    $status = file_put_contents($filename, $updatedfile);
    
    if (!$status) {
      $write["status_msg"] .= "Save error for kilpailijat. ";
    }
   
    // delete route
    $deleted = FALSE;
    $filename = KARTAT_DIRECTORY."merkinnat_".$eventid.".txt";
    $oldfile = file($filename);
    $updatedfile = array();
    foreach ($oldfile as $row) {
      $data = explode("|", $row);
      if ($data[0] == $courseid) {
        $deleted = TRUE;
      } else {
        $updatedfile[] = $row;
      }
    }
    $status = file_put_contents($filename, $updatedfile);   
  
    if (!$status) {
      $write["status_msg"] .= " Save error for merkinnat. ";
    }
    
    // delete course template
    $filename = KARTAT_DIRECTORY."radat_".$eventid.".txt";
    $oldfile = file($filename);
    $updatedfile = array();
    $deleted = FALSE;
    foreach ($oldfile as $row) {
      $data = explode("|", $row);
      if ($data[0] == $courseid) {
        $deleted = TRUE;
      } else {
        $updatedfile[] = $row;
      }
    }
    $status = file_put_contents($filename, $updatedfile);
    
    if (!$status) {
      $write["status_msg"] .= "Save error for radat. ";
    }    
    
    // delete course template
    $filename = KARTAT_DIRECTORY."ratapisteet_".$eventid.".txt";
    $oldfile = file($filename);
    $updatedfile = array();
    $deleted = FALSE;
    foreach ($oldfile as $row) {
      $data = explode("|", $row);
      if ($data[0] == $courseid) {
        $deleted = TRUE;
      } else {
        $updatedfile[] = $row;
      }
    }
    $status = file_put_contents($filename, $updatedfile);
    
    if (!$status) {
      $write["status_msg"] .= "Save error for ratapisteet. ";
    }    

    // delete course names
    $filename = KARTAT_DIRECTORY."sarjat_".$eventid.".txt";
    $oldfile = file($filename);
    $updatedfile = array();
    $deleted = FALSE;
    foreach ($oldfile as $row) {
      $data = explode("|", $row);
      if ($data[0] == $courseid) {
        $deleted = TRUE;
      } else {
        $updatedfile[] = $row;
      }
    }
    $status = file_put_contents($filename, $updatedfile);
    
    if (!$status) {
      $write["status_msg"] .= "Save error for sarjat. ";
    } 
  
    // delete course control list
    $filename = KARTAT_DIRECTORY."sarjojenkoodit".$eventid.".txt";
    $oldfile = file($filename);
    $updatedfile = array();
    $deleted = FALSE;
    foreach ($oldfile as $row) {
      $data = explode("|", $row);
      if ($data[0] == $courseid) {
        $deleted = TRUE;
      } else {
        $updatedfile[] = $row;
      }
    }
    $status = file_put_contents($filename, $updatedfile);
    
    if (!$status) {
      $write["status_msg"] .= "Save error for sarjojenkoodit. ";
    } 
            
  } else {
    $write["status_msg"] = "Invalid course id. ";  
  }
  
  if ($write["status_msg"] == "") {
    $write["ok"] = TRUE;
    $write["status_msg"] = "Course deleted.";
    rg2log("Course deleted|".$eventid."|".$courseid);
  } else {
    $write["ok"] = FALSE;    
  }
  
  return($write);
}

function deleteRoute($eventid) {
  $write["status_msg"] = "";
  if (isset($_GET['routeid'])) {
    $routeid = $_GET['routeid'];
    // delete comments
    $filename = KARTAT_DIRECTORY."kommentit_".$eventid.".txt";
    $oldfile = file($filename);
    $updatedfile = array();
    $deleted = FALSE;
    foreach ($oldfile as $row) {
      $data = explode("|", $row);
      if ($data[1] == $routeid) {
        $deleted = TRUE;
      } else {
        $updatedfile[] = $row;
      }
    }
    $status = file_put_contents($filename, $updatedfile);
    
    if (!$status) {
      $write["status_msg"] .= "Save error for kommentit. ";
    }

    // delete GPS details in result record
    if ($routeid >= GPS_RESULT_OFFSET) {
      $filename = KARTAT_DIRECTORY."kilpailijat_".$eventid.".txt";
      $oldfile = file($filename);
      $updatedfile = array();
      foreach ($oldfile as $row) {
        $data = explode("|", $row);
        $deleted = FALSE;
        if ($data[0] == $routeid) {
          $deleted = TRUE;                    
        } else {
          $updatedfile[] = $row;
        }
      }
      $status = file_put_contents($filename, $updatedfile);
    
      if (!$status) {
        $write["status_msg"] .= "Save error for kilpailijat. ";
      }

    }
      
    // delete route
    $deleted = FALSE;
    $filename = KARTAT_DIRECTORY."merkinnat_".$eventid.".txt";
    $oldfile = file($filename);
    $updatedfile = array();
    foreach ($oldfile as $row) {
      $data = explode("|", $row);
      if ($data[1] == $routeid) {
        $deleted = TRUE;
      } else {
        $updatedfile[] = $row;
      }
    }
    $status = file_put_contents($filename, $updatedfile);   
  
    if (!$status) {
      $write["status_msg"] .= " Save error for merkinnat. ";
    }
    if (!$deleted) {
      $write["status_msg"] .= "Invalid route id. ";
    }
  } else {
    $write["status_msg"] = "Invalid route id. ";  
  }
  
  if ($write["status_msg"] == "") {
    $write["ok"] = TRUE;
    $write["status_msg"] = "Route deleted";
    rg2log("Route deleted|".$eventid."|".$routeid);
  } else {
    $write["ok"] = FALSE;    
  }
  
  return($write);
}

function addNewRoute($eventid, $data) {
  //rg2log("Add new route for eventid ".$eventid);
  $newresult = FALSE;
  $id = $data->resultid;
  if (($data->resultid == 0) || ($data->resultid == GPS_RESULT_OFFSET)) {
    // result needs to be allocated a new id
    $newresult = TRUE;
    $rows = count(file(KARTAT_DIRECTORY."kilpailijat_".$eventid.".txt"));
    $rows++;
    if ($data->resultid == 0) {
      $id = $rows;
    } else {
      $id = $rows + GPS_RESULT_OFFSET;
    }  
  }

  $write["oldid"] = $data->resultid;
  $write["newid"] = $id;
  
  if ($id >= GPS_RESULT_OFFSET) {
    $name = ' GPS '.$data->name;
  } else {
    $name = $data->name;
  }
  //
  $name = encode_rg_output($name);
  // tidy up commments
  $comments = trim($data->comments);
  // remove HTML tags: probably not needed but do it anyway
  $comments = strip_tags($comments);
  //
  $comments = encode_rg_output($comments);
  // remove line breaks and keep compatibility with RG1
  $comments = str_replace("\r", "", $comments);
  $comments = str_replace("\n", "#cr##nl#", $comments);
  $newcommentdata = $data->courseid."|".$id."|".$name."||".$comments.PHP_EOL;

  // convert x,y to internal RG format
  $track = "";
  for ($i = 0; $i < count($data->x); $i++) {
    $track .= 'N'.$data->x[$i].';-'.$data->y[$i];
  }

  $controls = "";
  for ($i = 0; $i < count($data->controlx); $i++) {
    $controls .= 'N'.$data->controlx[$i].';-'.$data->controly[$i];
  }
  
  $newtrackdata = $data->courseid."|".$id."| ".$name."|null|".$track."|".$controls.PHP_EOL;

  $newresultdata = "";
  if (($newresult == TRUE) || ($id >= GPS_RESULT_OFFSET)) {
    // New result or GPS record so need to add result record as well
    // GPS track saved here as a point every three seconds
    // input can in theory have any time between points
    // so we need to interpolate
    $track= "";
    if ($id >= GPS_RESULT_OFFSET) {
      $oldtime = $data->time[0];
      $oldx = $data->x[0];
      $oldy = $data->y[0];
      $track = $data->x[0].';-'.$data->y[0].',0N';
      for ($i = 1; $i < count($data->x); $i++) {
        $difftime = $data->time[$i] - $oldtime;
        if ($difftime >= GPS_INTERVAL) {
          $xpersec = ($data->x[$i] - $oldx) / $difftime;
          $ypersec = ($data->y[$i] - $oldy) / $difftime;
          $time = GPS_INTERVAL;
          while ($time <= $difftime) {
            $track .= intval($oldx + ($xpersec * $time)).';-'.intval($oldy + ($ypersec * $time)).',0N';                
            $time = $time + GPS_INTERVAL;      
          }
          $oldx = intval($oldx + ($xpersec * $time));
          $oldy = intval($oldy + ($ypersec * $time));    
          $oldtime = $oldtime + $time - GPS_INTERVAL;    
        }
      }            
    }
  
    $newresultdata = $id."|".$data->courseid."|".$data->coursename."|".$name;
    $newresultdata .= "|".$data->startsecs."|||".$data->totaltime."||".$track.PHP_EOL;
  }

  $write["status_msg"] = "";
  
  if (($handle = @fopen(KARTAT_DIRECTORY."kommentit_".$eventid.".txt", "a")) !== FALSE) {
    $status =fwrite($handle, $newcommentdata);    
    if (!$status) {
      $write["status_msg"] = "Save error for kommentit. ";
    }
    @fflush($handle);
    @fclose($handle);
  }
  
  if (($handle = @fopen(KARTAT_DIRECTORY."merkinnat_".$eventid.".txt", "a")) !== FALSE) {
    $status =fwrite($handle, $newtrackdata);  
    if (!$status) {
      $write["status_msg"] .= " Save error for merkinnat. ";
    }
    @fflush($handle);
    @fclose($handle);
  }

  if (($newresult == TRUE) || ($id >= GPS_RESULT_OFFSET)) {
    if (($handle = @fopen(KARTAT_DIRECTORY."kilpailijat_".$eventid.".txt", "a")) !== FALSE) {
      $status =fwrite($handle, $newresultdata);   
      if (!$status) {
        $write["status_msg"] .= " Save error for kilpailijat.";
      }
      @fflush($handle);
      @fclose($handle);
    }
  }

  if ($write["status_msg"] == "") {
    $write["ok"] = TRUE;
    $write["status_msg"] = "Record saved";
    rg2log("Route saved|".$eventid."|".$id);
  } else {
    $write["ok"] = FALSE;    
  }
  
  return $write;

}

function addNewMap($data) {
  //rg2log("Add new map);
  $write["status_msg"] = "";
  if (($handle = @fopen(KARTAT_DIRECTORY."kartat.txt", "r+")) !== FALSE) {
    // read to end of file to find last entry
    $oldid = 0;
    while (($olddata = fgetcsv($handle, 0, "|")) !== FALSE) {  
      $oldid = intval($olddata[0]);
    }
    $newid = $oldid + 1;
    $renameFile = rename(KARTAT_DIRECTORY."temp.jpg", KARTAT_DIRECTORY.$newid."jpg");
    if ($renameFile) {
      $newmap = $newid."|".$data->name;
      if ($data->georeferenced) {
        $newmap .= "|||||||||||||".$data->A."| ".$data->D."|".$data->B."|".$data->E."|".$data->C."|".$data->F;
      }
      $newmap .= PHP_EOL;
      $write["newid"] = $newid;
      $status =fwrite($handle, $newmap);    
      if (!$status) {
        $write["status_msg"] = "Save error for kartat. ";
      }
    } else {
        $write["status_msg"] = "Error renaming map file. ";
    }
    @fflush($handle);
    @fclose($handle);
  }


  if ($write["status_msg"] == "") {
    $write["ok"] = TRUE;
    $write["status_msg"] = "Map added";
    rg2log("Map added|".$newid);
  } else {
    $write["ok"] = FALSE;    
  }
  
  return $write;

}

function lockDatabase() {
    // lock directory version based on http://docstore.mik.ua/oreilly/webprog/pcook/ch18_25.htm
    // but note mkdir returns TRUE if directory already exists!
    $locked = FALSE;
    if (is_dir(LOCK_DIRECTORY)) {
      // locked already by someone else
      rg2log("Directory exists");
    } else {
      // try to lock it ourselves
     //rg2log("Trying to lock");
      $locked = mkdir(LOCK_DIRECTORY ,0777);
    }
    $tries = 0;
    while (!$locked && ($tries < 5)) {
      // wait 500ms up to 5 times trying to get a lock
      usleep(500000);
      if (is_dir(LOCK_DIRECTORY)) {
        // locked already by someone else
        $locked = FALSE;
      } else {
        // try to lock it ourselves
        $locked = mkdir(LOCK_DIRECTORY,0777);
      }
      $tries++;
      //rg2log("Lock attempt ".$tries);
  }
  //rg2log("Lock status ".$locked);
  return $locked;
}

function unlockDatabase() {
  // ignore any errors but try to tidy up everything
  @rmdir(LOCK_DIRECTORY);         
}

function handleGetRequest($type, $id) {
  $output = array();
     
  switch ($type) {  
  case 'events':
    $output = getAllEvents();
    break;    
  case 'courses':
    $output = getCoursesForEvent($id);
    break;  
  case 'results':
    $output = getResultsForEvent($id);
    break;
  case 'maps':
    $output = getMaps();
    break;
  case 'tracks':
    $output = getTracksForEvent($id);
    break;
  default:
    die("Request not recognised: ".$type);
    break;
  }
  // output JSON data
  header("Content-type: application/json"); 
  echo "{\"data\":" .json_encode($output). "}";
}

function getAllEvents() {
  $output = array();
  $referenced = 0;
  $maps = array();
  if (($handle = fopen(KARTAT_DIRECTORY."kartat.txt", "r")) !== FALSE) {
    while (($data = fgetcsv($handle, 0, "|")) !== FALSE) {
      // mapid, map name, 12 old georef values, 6 world file values
      if (count($data) == 20) {
        $maps[$referenced]["mapid"] = intval($data[0]);
        $maps[$referenced]["A"] = $data[14];
        $maps[$referenced]["D"] = $data[15];
        $maps[$referenced]["B"] = $data[16];
        $maps[$referenced]["E"] = $data[17];
        $maps[$referenced]["C"] = $data[18];
        $maps[$referenced]["F"] = $data[19];
        $referenced++;
      }
    }
    fclose($handle);
  }

  $row = 0;
  if (($handle = fopen(KARTAT_DIRECTORY."kisat.txt", "r")) !== FALSE) {
    while (($data = fgetcsv($handle, 0, "|")) !== FALSE) {
      $detail = array();
      $detail["id"] = intval($data[0]);
      $detail["mapid"] = intval($data[1]);
      for ($i = 0; $i < $referenced; $i++) {
        if ($detail["mapid"] == $maps[$i]["mapid"]) {
          $detail["A"] = $maps[$i]["A"];
          $detail["B"] = $maps[$i]["B"];
          $detail["C"] = $maps[$i]["C"];
          $detail["D"] = $maps[$i]["D"];
          $detail["E"] = $maps[$i]["E"];
          $detail["F"] = $maps[$i]["F"];          
        }
      }

      $detail["format"] = $data[2];
      // Issue #11: found a stray &#39; in a SUFFOC file
      $name = encode_rg_input($data[3]);
      $detail["name"] = str_replace("&#39;", "'", $name);
      $detail["date"] = $data[4];
      $detail["club"] = encode_rg_input($data[5]);
      $detail["type"] = $data[6];
      $detail["comment"] = encode_rg_input($data[7]);
      $output[$row] = $detail;        
      $row++;
    }
    fclose($handle);
  }
  return $output;
}

function getMaps() {
  $output = array();
  $row = 0;
  if (($handle = fopen(KARTAT_DIRECTORY."kartat.txt", "r")) !== FALSE) {
    while (($data = fgetcsv($handle, 0, "|")) !== FALSE) {
      $detail = array();
      $detail["mapid"] = intval($data[0]);
      $detail["name"] = $data[1];
      if (count($data) == 20) {
        $detail["A"] = $data[14];
        $detail["D"] = $data[15];
        $detail["B"] = $data[16];
        $detail["E"] = $data[17];
        $detail["C"] = $data[18];
        $detail["F"] = $data[19];
        $detail["georeferenced"] = TRUE;
      } else {
        $detail["A"] = 0;
        $detail["D"] = 0;
        $detail["B"] = 0;
        $detail["E"] = 0;
        $detail["C"] = 0;
        $detail["F"] = 0;
        $detail["georeferenced"] = FALSE;
      }
      $output[$row] = $detail;        
      $row++;
    }
    fclose($handle);
  }
  return $output;
}

function isScoreEvent($eventid) {
  if (($handle = @fopen(KARTAT_DIRECTORY."kisat.txt", "r")) !== FALSE) {
    while (($data = fgetcsv($handle, 0, "|")) !== FALSE) {
      if ($data[0] == $eventid) {
        if ($data[2] == 3) {
          return TRUE;
        } else {
          return FALSE;
        }
      }
    }
  }
  return FALSE;
}

function getResultsForEvent($eventid) {
  $output = array();
  $comments = 0;  
  $text = array();
  // @ suppresses error report if file does not exist
  if (($handle = @fopen(KARTAT_DIRECTORY."kommentit_".$eventid.".txt", "r")) !== FALSE) {
    while (($data = fgetcsv($handle, 0, "|")) !== FALSE) {
      if (count($data) >= 5) {
        // remove null comments
        if (strncmp($data[4], "Type your comment", 17) != 0) {
           $text[$comments]["resultid"] = $data[1];
           // replace carriage return and line break codes
           $temp = encode_rg_input($data[4]);  
           // RG1 uses #cr##nl# to allow saving to text file
           $temp = str_replace("#cr##nl#", "\n", $temp);  
           $text[$comments]["comments"] = $temp;
           $comments++;
        }
      }
    }
    fclose($handle);
  }

  if (isScoreEvent($eventid)) {
    if (($handle = @fopen(KARTAT_DIRECTORY."ratapisteet_".$eventid.".txt", "r")) !== FALSE) {
      $row = 0;  
      while (($data = fgetcsv($handle, 0, "|")) !== FALSE) {
        $x = array();
        $y = array();
        // field is N separated and then comma separated  
        $pairs = explode("N", $data[1]);
        for ($j = 0; $j < count($pairs); $j++) {
          $xy = explode(";", $pairs[$j]);
          // some courses seem to have nulls at the end so just ignore them
          if ($xy[0] != "") {
            $x[$j] = 1 * $xy[0];
            // make it easier to draw map
            $y[$j] = -1 * $xy[1];
          }            
         }
        $scoreref[$row] = $data[0];
        $xpos[$row] = $x;
        $ypos[$row] = $y;
        $row++;    
      }
      fclose($handle);
    }
  }

  // @ suppresses error report if file does not exist
  if (($handle = @fopen(KARTAT_DIRECTORY."kilpailijat_".$eventid.".txt", "r")) !== FALSE) {
    $row = 0;
    while (($data = fgetcsv($handle, 0, "|")) !== FALSE) {
      $detail = array();
      $detail["resultid"] = intval($data[0]);
      $detail["courseid"] = intval($data[1]);
      $detail["coursename"] = encode_rg_input($data[2]);
      $detail["name"] = encode_rg_input($data[3]);
      $detail["starttime"] = intval($data[4]);
      $detail["databaseid"] = $data[5];
      $detail["scoreref"] = $data[6];
      if ($data[6] != "") {
        for ($i = 0; $i < count($scoreref); $i++) {
          if ($scoreref[$i] == $data[6]) {
            $detail["scorex"] = $xpos[$i];  
            $detail["scorey"] = $ypos[$i];  
          }
        }          
      }
      // remove ".:" and "::" which RG1 can generate when adding results
      $t = str_replace('.:', ':', $data[7]);
      $t = str_replace('::', ":", $t);
      $detail["time"] = $t;
      // trim trailing ; which create null fields when expanded
      $temp = rtrim($data[8], ";");
      // split array at ; and force to integers
      $detail["splits"] = array_map('intval', explode(";", $temp));
      
      if (sizeof($data) > 9) {
        // list allocates return values in an array to the specified variables 
        list($detail["gpsx"], $detail["gpsy"]) = expandCoords($data[9]);
      } else {
        $detail["gpsx"] = ""; 
        $detail["gpsy"] = ""; 
      }
      
      $detail["comments"] = "";
      for ($i = 0; $i < $comments; $i++) {
        if ($detail["resultid"] == $text[$i]["resultid"]) {
          $detail["comments"] = $text[$i]["comments"];      
        }
      }  
      $output[$row] = $detail;        
      $row++;
    }
    fclose($handle);
  }
  return $output;
}

function getCoursesForEvent($eventid) {
  $output = array();
  $row = 0;
  // extract control codes
  $controlsFound = FALSE;
  $controls = array();
  $xpos = array();
  $ypos = array();
  $dummycontrols = array();
  // @ suppresses error report if file does not exist
  // read control codes for each course
  if (($handle = @fopen(KARTAT_DIRECTORY."sarjojenkoodit_".$eventid.".txt", "r")) !== FALSE) {
    $controlsFound = TRUE;
    while (($data = fgetcsv($handle, 0, "|")) !== FALSE) {
      // ignore first field: it is an index  
      $codes = array();
      for ($j = 1; $j < count($data); $j++) {
        $codes[$j - 1] = $data[$j];
      }  
      $controls[$row] = $codes;
      $row++;    
    }
    fclose($handle);
  }

  // extract control locations based on map co-ords
  if (($handle = @fopen(KARTAT_DIRECTORY."ratapisteet_".$eventid.".txt", "r")) !== FALSE) {
    $row = 0;  
    while (($data = fgetcsv($handle, 0, "|")) !== FALSE) {
      // ignore first field: it is an index  
      $x = array();
      $y = array();
      $dummycodes = array();
      // field is N separated and then comma separated  
      $pairs = explode("N", $data[1]);
      for ($j = 0; $j < count($pairs); $j++) {
        $xy = explode(";", $pairs[$j]);
        // some courses seem to have nulls at the end so just ignore them
        if ($xy[0] != "") {
          $dummycodes[$j] = getDummyCode($pairs[$j]);
          $x[$j] = 1 * $xy[0];
          // make it easier to draw map
          $y[$j] = -1 * $xy[1];
        }            
      }
      $xpos[$row] = $x;
      $ypos[$row] = $y;
      $dummycontrols[$row] = $dummycodes;
      $row++;    
    }
    fclose($handle);
  }


  $row = 0; 
  // set up details for each course
  if (($handle = @fopen(KARTAT_DIRECTORY."sarjat_".$eventid.".txt", "r")) !== FALSE) {
    while (($data = fgetcsv($handle, 0, "|")) !== FALSE) {
      $detail = array();
      $detail["courseid"] = intval($data[0]);
      $detail["name"] = encode_rg_input($data[1]);
      if ($controlsFound) {
        // assuming files have same number of entries: should cross-check courseid?  
        $detail["codes"] = $controls[$row];
      } else {
        $detail["codes"] = $dummycontrols[$row];
      }
      $detail["xpos"] = $xpos[$row];
      $detail["ypos"] = $ypos[$row];
      $output[$row] = $detail;        
      $row++;
    }
    fclose($handle);
  }
  return $output;
}

function expandCoords($coords) {
  // Split Nxx;-yy,0Nxxx;-yy,0N.. into x,y arrays
  // but note that somestimes you don't get the ,0
  $x = array();
  $y = array();
  $xy = explode("N", $coords);
  foreach ($xy as $point) {
    $temp = explode(";", $point);
    if (count($temp) == 2) {
      $x[] = $temp[0];
      // strip off trailing ,0 if it exists
      $pos = strpos(',', $temp[1]);
      if ($pos) {
        // remove leading - by starting at 1
        $y[] = substr($temp[1], 1, $pos - 2);        
      } else {
        $y[] = substr($temp[1], 1);
      }

    }
  }
  // return the two arrays converted to integer values
  return array(array_map('intval', $x), array_map('intval', $y)); 
}

function getDummyCode($code) {
  // create dummy control codes if the results didn't include any
  static $codes = array();
  static $count = 0;
  $dummycode = 0;
  for ($i = 0; $i < $count; $i++) {
    if ($codes[$i] == $code) {
      $dummycode = $i + 1;
    }
  }
  if ($dummycode == 0) {
    $codes[$count] = $code;
    $count++;
    $dummycode = $count;
  }
  //rg2log($code.' becomes '.$dummycode);
  // force to a string since it helps elsewhere and it shows that these are dummy values
  return 'X'.$dummycode;
  
}

function getTracksForEvent($eventid) {
  $output = array();
  $row = 0;
  // @ suppresses error report if file does not exist
  if (($handle = @fopen(KARTAT_DIRECTORY."merkinnat_".$eventid.".txt", "r")) !== FALSE) {
    while (($data = fgetcsv($handle, 0, "|")) !== FALSE) {
      $detail = array();
      $detail["courseid"] = intval($data[0]);
      $detail["resultid"] = intval($data[1]);
      $detail["name"] = encode_rg_input($data[2]);
      $detail["mystery"] = $data[3];
      list($detail["gpsx"], $detail["gpsy"]) = expandCoords($data[4]);
      if (count($data) > 5) {
        $detail["controls"] = $data[5];
      } else {
        $detail["controls"] = "";
      }
      $output[$row] = $detail;        
      $row++;
    }
    fclose($handle);
  }
  return $output;
}

function rg2log($msg) {
  if (defined('RG_LOG_FILE')){
    $user_agent = $_SERVER['HTTP_USER_AGENT']; 
    if( ! ini_get('date.timezone') ) {
     date_default_timezone_set('GMT');
    }
    error_log(date("c", time())."|".$user_agent."|".$msg.PHP_EOL, 3, RG_LOG_FILE);
  }
}

function generateCookie() {
  // simple cookie generator! Don't need unique, just need something vaguely random
  $keksi = substr(str_shuffle("abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890"), 0, 20);
  // overwrite old cookie file  
  //TODO add error check
  file_put_contents($manager_url."keksi.txt", $keksi);
  return $keksi;  
}

?>
