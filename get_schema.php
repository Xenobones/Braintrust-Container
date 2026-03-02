 <?php
     session_start();
     // 1. Load the configuration that we know (usually) works
     require_once '/var/www/secure_config/braintrust_config.php';

     if (!isset($_SESSION['user_id'])) {
         die("Unauthorized.");
     }

     // 2. Check if the connection from config is alive
     if ($conn->connect_error) {
         die("Connection failed.");
     }
    
    header('Content-Type: text/plain');
   
    // 3. Get list of tables
    $tables = [];
    $result = $conn->query("SHOW TABLES");
    while ($row = $result->fetch_array()) {
        $tables[] = $row[0];
    }
   
    // 4. Loop through tables and print their CREATE statement
    foreach ($tables as $table) {
       $createResult = $conn->query("SHOW CREATE TABLE `$table`");
       $createRow = $createResult->fetch_assoc();
   
        echo "-- Structure for table `$table`\n";
   echo $createRow['Create Table'] . ";\n\n";
}

   $conn->close();
   ?>