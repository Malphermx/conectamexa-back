<?php
header('Access-Control-Allow-Origin: *');
header("Access-Control-Allow-Headers: X-API-KEY, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method");
header("Content-Type: application/json; charset=utf-8"); // Cambié a application/json para estar en línea con tu uso de JSON

function conectarDB() {
    $servidor = "localhost";
    $usuario = "u709694486_usuario";
    // $usuario = "root";
    $password = "br7L4Ry~>J>9";
    // $password = "";
    $bd = "u709694486_conectamexa";
    // $bd = "conectamexa";

    // Intenta establecer la conexión
    $conexion = mysqli_connect($servidor, $usuario, $password, $bd);

    // Manejo de errores de conexión
    if (!$conexion) {
        // Aquí se puede manejar el error de una manera más amigable o logear el error
        die(json_encode(array('error' => 'Error en la conexión a la base de datos: ' . mysqli_connect_error())));
    }

    // Devolver la conexión exitosa
    return $conexion;
}
?>
