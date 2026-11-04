<?php
require_once __DIR__ . '/config/database.php';
$pdo = db();
$users = [
 ['System Administrator','admin@bup.local','Admin','System Administrator','01700000001'],
 ['Event Management Officer','event@bup.local','Event Management','Event Management Officer','01700000002'],
 ['Protocol Officer','protocol@bup.local','Protocol Officer','Military Protocol Officer','01700000003'],
 ['Duty Personnel','duty@bup.local','Duty Personnel','Duty Coordinator','01700000004'],
 ['BUP Official','official@bup.local','BUP Official','University Official','01700000005'],
];
foreach($users as $u){
  $hash=password_hash('password', PASSWORD_DEFAULT);
  $s=$pdo->prepare("UPDATE users SET password_hash=? WHERE email=?");
  $s->execute([$hash,$u[1]]);
}
echo '<h2>Setup completed.</h2><p>Demo passwords have been securely hashed. Delete setup.php after first successful run.</p><p><a href="index.php">Go to Home</a></p>';
