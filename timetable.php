<?php
require_once 'config.php';
require_login();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Timetable — GMI</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" href="img/favicon.png" type="image/png">
  <link rel="stylesheet" href="style.css">
</head>
<body>
  <?php include 'nav.php'; ?>

  <div style="max-width:1100px;margin:50px auto;">
    <h1>TIMETABLE</h1>

    <div class="search-bar">
      <select id="semesterSelect"><option value="">-- Select Semester --</option><?php for($i=1;$i<=6;$i++): ?><option value="Sem<?=$i?>">SEMESTER <?=$i?></option><?php endfor; ?></select>
      <select id="classSelect"><option value="">-- Select Class --</option><option value="DCBS">DCBS</option><option value="DSWE">DSWE</option><option value="DCRM">DCRM</option></select>
      <select id="numClassSelect"><option value="">-- No. Class --</option><?php for($i=1;$i<=6;$i++): ?><option value="<?=$i?>"><?=$i?></option><?php endfor; ?></select>
      <button onclick="showTimetable()">Search</button>
    </div>

    <div class="timetable-content">
      <img id="timetableImage" src="img/tt2.gif" class="timetable-image" alt="Timetable">
    </div>

    <div class="timetable-buttons">
      <a href="dashboard.php" class="btn btn-primary">DASHBOARD</a>
      <a href="#" onclick="saveImage()" class="btn btn-secondary">SAVE IMAGE</a>
    </div>
  </div>

<script>
function showTimetable(){
  const semester = document.getElementById('semesterSelect').value;
  const cls = document.getElementById('classSelect').value;
  const num = document.getElementById('numClassSelect').value;
  if (!semester || !cls || !num) { alert('Please select Semester, Class and No. Class first.'); return; }
  document.getElementById('timetableImage').src = 'img/' + semester + '_' + cls + num + '.jpg';
}
function saveImage(){
  const link = document.createElement('a');
  link.href = document.getElementById('timetableImage').src;
  link.download = 'timetable.jpg';
  link.click();
}
</script>
</body>
</html>
