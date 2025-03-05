<?php

	include "funciones/odsasqlite.php";
	odsasqlite($_POST['url'],$_POST['nombre']);
	
?>
<p>Proceso completado</p>
<a href="index.php">Ahora accede al escritorio</a>

