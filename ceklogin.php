<?php
require("config/config.default.php");
$username = trim(isset($_POST['username']) ? $_POST['username'] : '');
$password = isset($_POST['password']) ? $_POST['password'] : '';
if ($username <> "" and $password <> "") {
	$stmt = mysqli_prepare($koneksi, "SELECT id_siswa, password, status FROM siswa WHERE username = ? LIMIT 1");
	if (!$stmt) {
		echo "td";
		exit();
	}
	mysqli_stmt_bind_param($stmt, 's', $username);
	mysqli_stmt_execute($stmt);
	mysqli_stmt_bind_result($stmt, $siswaId, $siswaPassword, $siswaStatus);
	$found = mysqli_stmt_fetch($stmt);
	mysqli_stmt_close($stmt);
	if (!$found) {
		echo "td";
	} else {
		if ($siswaStatus == "aktif") {
			//$ceklogin=mysqli_num_rows(mysqli_query($koneksi, "select * from login where id_siswa='$siswa[id_siswa]'"));
			$storedPassword = (string) $siswaPassword;
			$hashInfo = password_get_info($storedPassword);
			$isHashedPassword = $hashInfo['algo'] !== 0;
			$passwordValid = $isHashedPassword
				? password_verify($password, $storedPassword)
				: hash_equals($storedPassword, $password);

			if (!$passwordValid) {
				echo "nopass";
			} else {
				if (!$isHashedPassword || password_needs_rehash($storedPassword, PASSWORD_DEFAULT)) {
					$newPassword = password_hash($password, PASSWORD_DEFAULT);
					$update = mysqli_prepare($koneksi, "UPDATE siswa SET password = ? WHERE id_siswa = ?");
					if ($update) {
						mysqli_stmt_bind_param($update, 'si', $newPassword, $siswaId);
						mysqli_stmt_execute($update);
						mysqli_stmt_close($update);
					}
				}
				//if($ceklogin==0){
				session_regenerate_id(true);
				$_SESSION['id_siswa'] = $siswaId;
				mysqli_query($koneksi, "INSERT INTO log (id_siswa,type,text,date) VALUES ('$siswaId','login','masuk','$tanggal $waktu')");
				mysqli_query($koneksi, "UPDATE siswa set online='1' where id_siswa='$siswaId'");
				echo "ok";
				//}else{
				//	echo "nologin";
				//}
			}
		} else {
			echo "ta";
		}
	}
}
