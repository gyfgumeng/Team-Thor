<html dir="ltr" lang="en">
<head>
	<?php
	session_save_path("./sessionDir");
	session_start();
	require_once "./.dblogin.php";
	require_once "./php/meta.php";
	?>
</head>
<body>
	<?php
	$conn = dbconnect();
	require_once "./php/header.php";
	?>

	<div class="container-md mb-3">
		<?php
		function showError($title,$message){
			echo '<div class="card shadow bg-light container-fluid my-2 p-3">';
			echo '<h1 class="text-center text-danger">'.$title.'</h1>';
			echo '<h3 class="text-center text-body">'.$message.'</h3>';
			echo '<a href="./index.php" class="mx-auto mt-2 btn btn-primary">Go Back</a>';
			echo '</div>';
		}

		if(isset($_GET["location"]) && userIsLoggedIn()){
			//send query for location data
			$query = "SELECT locations.name as name,lat,lon FROM locations ";
			$query .= "JOIN Account ON Account.lid=locations.user_lid ";
			$query .= "WHERE locations.name='".$_GET["location"]."' ";
			$query .= "AND Account.username='".$_SESSION["user"]."'";
			$result = mysqli_query($conn,$query);

			if($result && mysqli_num_rows($result) == 1){
				$loc = mysqli_fetch_array($result);

				//read api key
				$apiKeyFile = fopen("api_secret_key","r");
				if(!$apiKeyFile){
					showError("Failed To Read API Key","Could not open the api key file");
					die();
				}
				$apiKey = trim(fread($apiKeyFile,filesize("api_secret_key")));
				fclose($apiKeyFile);

				//show loading spinner
				echo '<div class="card shadow bg-light my-2 py-3" id="loadingSpinner">';
				echo '<div class="spinner-border mx-auto" role="status" style="width:3rem;height:3rem;">';
				echo '<span class="sr-only">Loading...</span>';
				echo '</div></div>';
				
				//get dates for historical data
				$currentHour = intval(date("H"));
				$currentMinute = intval(date("i"));
				$currentSecond = intval(date("s"));
				$todayStart = time() - (($currentHour*3600) + ($currentMinute*60) + ($currentSecond)) + 1;
				$dt = Array(
					$todayStart - (86400 * 5),
					$todayStart - (86400 * 4),
					$todayStart - (86400 * 3),
					$todayStart - (86400 * 2),
					$todayStart - (86400 * 1)
				);
				
				//loop through past days and get historical data
				$hist = Array();
				foreach($dt as $time){
					//fetch historical data
					$apiUrl = "https://api.openweathermap.org/data/2.5/onecall/timemachine";
					$apiUrl .= "?lat=".urlencode($loc["lat"]);
					$apiUrl .= "&lon=".urlencode($loc["lon"]);
					$apiUrl .= "&apikey=".urlencode($apiKey);
					$apiUrl .= "&dt=".urlencode($time);
					$apiUrl .= "&units=metric";

					$curl = curl_init();
					curl_setopt_array($curl, [
						CURLOPT_URL => $apiUrl,
						CURLOPT_RETURNTRANSFER => 1
					]);
					$history = json_decode(curl_exec($curl));
					array_push($hist, $history);	//add hourly data
				}

				//calculate minumum and maximum temperature
				$maxTemp = -272;
				$minTemp = 100;
				foreach($hist as $day){
					foreach($day->{"hourly"} as $hour){
						$maxTemp = max($maxTemp,round($hour->{"temp"}));
						$minTemp = min($minTemp,round($hour->{"temp"}));
					}
				}
				$scalingFactor = 200/($maxTemp - $minTemp);

				//delete loading spinner
				echo '<script>document.getElementById("loadingSpinner").remove()</script>';

				//render temperature
				echo '<div class="card shadow container-fluid my-2 py-2 bg-light">';
				echo '<h2 class="text-dark ml-4">Temperature</h2>';	//header
				echo '<table class="table table-sm border-bottom w-auto mx-auto" style="min-width:50%">';
				echo '<tr><td>Maximum Temperature</td><td>'.$maxTemp.'</td></tr>';	//maximum temperature
				echo '<tr><td>Mimimum Temperature</td><td>'.$minTemp.'</td></tr>';	//minimum temperature
				echo '</table>';
				echo '<div class="w-100 d-flex flex-row overflow-hidden">';
				echo '<div class="d-flex flex-column justify-content-between mr-1">';
				echo '<span class="text-danger">'.$maxTemp.'C</span>';
				echo '<span class="text-primary">'.$minTemp.'C</span>';
				echo '</div>';
				echo '<div class="flex-grow-1 d-flex flex-row py-2 overflow-auto align-items-end" ';
				echo 'style="-ms-overflow-style:none;scrollbar-width:none;">';
				foreach($hist as $dayHist){	//loop through days
					$first = True;
					foreach($dayHist->{"hourly"} as $hourHist){	//loop through hours
						$day = date("l",$hourHist->{"dt"});
						$hour = date("H",$hourHist->{"dt"}); 
						$temp = round($hourHist->{"temp"});
						$background = "bg-info";
						if($first){
							$first = False;
							$background = "bg-dark";
						}
						echo '<div class="'.$background.' p-1 rounded" ';
						echo 'data-toggle="tooltip" data-html="true" data-placement="bottom" title="';
						echo $day.', '.$hour.":00 GMT<br>";
						echo $temp.'C" ';
						echo 'style="height:0;margin-right:2px;height:';
						echo strval(($temp-$minTemp)*$scalingFactor).'">';
						echo '</div>';
					}
				}
				echo '</div></div>';
				echo '<div class="text-center text-muted">Some parts of the graph may be cut off, scroll ';
				echo 'right to see them</div>';
				echo '</div>';

				//render weather history
				echo '<div class="card shadow container-fluid my-2 py-2 bg-light">';
				echo '<h2 class="text-dark ml-4">Weather</h2>';
				echo '<table class="table table-sm w-auto mx-auto" style="min-width:75%">';
				echo '<colgroup>';
				echo '<col class="text-body font-weight-bold">';
				echo '<col><col><col><col><col>';
				echo '</colgroup>';
				echo '<tr><th></th>';	//header row
				foreach($hist as $dayHist){
					$day = date("l",$dayHist->{"current"}->{"dt"});
					$date = date("Y-m-d",$dayHist->{"current"}->{"dt"});
					echo '<th class="text-center">'.substr($day,0,3);
					echo '<br><span class="text-muted font-weight-normal">'.$date.'</span></th>';
				}
				echo '</tr>';
				for($i=0;$i<24;$i++){
					echo '<tr><th>';
					if($i<10) echo '0';
					echo $i.':00</th>';
					foreach($hist as $dayHist){
						$weatherDescrip = $dayHist->{"hourly"}[$i]->{"weather"}[0]->{"description"};
						$weather_id = $dayHist->{"hourly"}[$i]->{"weather"}[0]->{"id"};
						if($weather_id < 300) $weatherIcon = "./Icons/Thunderstorm.png";	//thunderstorms
						else if($weather_id < 600) $weatherIcon = "./Icons/Rain.png";	//drizzle & rain
						else if($weather_id < 700) $weatherIcon = "./Icons/Snow.png";	//snow
						else if($weather_id < 800) $weatherIcon = "";	//atmospheric conditions
						else if($weather_id < 801) $weatherIcon = "./Icons/Sun.png";	//clear
						else $weatherIcon = "./Icons/Cloud.png";	//cloudy
						echo '<td class="text-center" title="'.$weatherDescrip.'">';
						echo '<img class="img-fluid" src="'.$weatherIcon.'" style="max-height:25px">';
						echo '</td>';
					}
					echo '</tr>';
				}
				echo '</table>';
				echo '</div>';

				//script to enable tooltips
				echo '<script>$("[data-toggle=\"tooltip\"]").tooltip()</script>';
			}
			else if(!$result){
				showError("SQL Query Failed",mysqli_error($conn));
			}
			else if(mysqli_num_rows($result) < 1){
				showError("Failed To Find Location","SQL query did not return any matching location");
			}
			else{
				showError("Too Many Results","The SQL query returned multiple matching locations");
			}
		}
		else if(!isset($_GET["location"])){
			showError("Missing Location","No location name was given");
		}
		else{
			showError("Not Logged In","You must be logged in to access location weather history");
		}
		?>
	</div>
</body>
</html>