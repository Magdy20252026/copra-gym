<?php
session_start();

// حذف كل بيانات الجلسة
$_SESSION = [];
session_unset();
session_destroy();

// الرجوع إلى صفحة تسجيل الدخول
header("Location: login.php");
exit;