<?php

use \Firebase\JWT\JWT;

include_once 'config.php';
require_once 'vendor/autoload.php';

// Mostrar errores para depuración
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Configurar CORS y tipo de contenido JSON
header('Access-Control-Allow-Origin: *');
header("Access-Control-Allow-Headers: X-API-KEY, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Allow: GET, POST, OPTIONS, PUT, DELETE");
header("Content-Type: application/json; charset=utf-8");

// Manejar las solicitudes preflight (OPTIONS) para CORS
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

include "conectar.php";
$mysqli = conectarDB();

// Verificar si la conexión es exitosa
if (!$mysqli) {
    http_response_code(500);
    echo json_encode(array('error' => 'Error de conexión a la base de datos: ' . mysqli_connect_error()));
    exit();
}

// Obtener datos del cuerpo de la solicitud
$JSONData = file_get_contents("php://input");
$dataObject = json_decode($JSONData);

// Iniciar sesión
session_start();
$mysqli->set_charset('utf8');

// Validar que se reciban los datos
if (!isset($dataObject->usuario) || !isset($dataObject->clave)) {
    http_response_code(400); // Bad Request
    echo json_encode(array('conectado' => false, 'error' => 'Datos incompletos.'));
    exit();
}

// Asignar los datos recibidos
$usuario = $dataObject->usuario;
$password = $dataObject->clave;

// Preparar y ejecutar la consulta SQL CORREGIDA
if ($nueva_consulta = $mysqli->prepare("SELECT 
    id, 
    username, 
    email, 
    password_hash,
    created_at
FROM admin_users 
WHERE email = ? OR username = ?")) {

    $nueva_consulta->bind_param('ss', $usuario, $usuario);
    $nueva_consulta->execute();
    $resultado = $nueva_consulta->get_result();

    if ($resultado->num_rows == 1) {
        $datos = $resultado->fetch_assoc();
        $password_hash_db = $datos['password_hash']; // CORREGIDO: usar password_hash en lugar de password

        if (password_verify($password, $password_hash_db)) {
            
            // Generar token JWT
            $token = array(
               "iss" => $issuer_claim,
               "aud" => $audience_claim,
               "iat" => time(),
               "exp" => time() + $jwt_expiration,
               "data" => array(
                   "id" => $datos['id'],
                   "username" => $datos['username'],
                   "email" => $datos['email'],
               )
            );
            
            $jwt = JWT::encode($token, $secret_key, 'HS256');

            // Respuesta exitosa
            echo json_encode(array(
                    'data' => array(
                        'token' => $jwt,
                        'conectado' => true,
                        'id' => $datos['id'],
                        'username' => $datos['username'],
                        'email' => $datos['email'],
                        'fecha_registro' => $datos['created_at']
                    )
            ));
        } else {
            http_response_code(401);
            echo json_encode(array(
                'conectado' => false, 
                'error' => 'La contraseña es incorrecta.'
            ));
        }
    } else {
        http_response_code(404);
        echo json_encode(array(
            'conectado' => false, 
            'error' => 'El usuario no existe o está inactivo.'
        ));
    }

    $nueva_consulta->close();
} else {
    http_response_code(500);
    echo json_encode(array(
        'conectado' => false, 
        'error' => 'Error en la consulta: ' . $mysqli->error
    ));
}

$mysqli->close();
?>