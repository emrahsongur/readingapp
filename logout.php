<?php
require_once 'config/config.php';

// Tüm session değişkenlerini temizle
session_unset();

// Session'ı tamamen yok et
session_destroy();

// Güvenli bir şekilde login sayfasına yönlendir
header("Location: login.php");
exit;
?>