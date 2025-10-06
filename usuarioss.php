<?php
header("Access-Control-Allow-Origin: *");  // Permitir solo desde tu app de React
header("Access-Control-Allow-Headers: Authorization, Content-Type, Access-Control-Allow-Headers, X-Requested-With");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");  // Métodos permitidos
header("Access-Control-Allow-Credentials: true");  // Permitir envío de cookies o credenciales si fuera necesario

// Manejar las solicitudes preflight (OPTIONS)
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}


// Obtener el token JWT desde los encabezados
require 'auth_helpers.php';
require 'decode.php';

$headers = apache_request_headers();
$jwt = isset($headers['Authorization']) ? str_replace('Bearer ', '', $headers['Authorization']) : "";

if ($jwt && validateToken($jwt)) {
     $user_id=extractToken($jwt);
     $tipo_usuario = extractUserType($jwt);
 
    $method = $_SERVER['REQUEST_METHOD'];
    $input = json_decode(file_get_contents("php://input"), true);
    

    include "conectar.php";
    $conn = conectarDB();
    
    
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
    
    // Obtener el método HTTP
    $method = $_SERVER['REQUEST_METHOD'];
    
    switch ($method) {
        case 'GET':
            verificarAcceso('Administrador', $tipo_usuario);
            obtenerUsuarios($conn);
            break;
        case 'POST':
            verificarAcceso('Administrador', $tipo_usuario);
            crearUsuario($conn);
            break;
        case 'PUT':
            verificarAcceso('Administrador', $tipo_usuario);
            actualizarUsuario($conn);
            break;
        case 'DELETE':
            verificarAcceso('Administrador', $tipo_usuario);
            eliminarUsuario($conn);
            break;
        default:
            echo json_encode(["error" => "Método no soportado"]);
            break;
    }

    $conn->close();

} else {
    http_response_code(401);
    echo json_encode(array("message" => "Unauthorized"));
}

function verificarAcceso($rolRequerido, $tipoUsuarioActual) {
    switch($tipoUsuarioActual){
        case "2":
        $tipoUsuarioActual = 'Usuario';
        break;
        default:
        $tipoUsuarioActual = 'Administrador';
    }
    if ($tipoUsuarioActual !== $rolRequerido) {
        http_response_code(403);
        echo json_encode(["error" => "Acceso denegado"]);
        exit();
    }
}

// Función para extraer el tipo de usuario del token JWT
function extractUserType($jwt) {
    $decoded = decodeToken($jwt); // Usa tu lógica de decodificación JWT aquí
    return $decoded['data']->idTipoUsuario  ?? null; // Asegúrate de que 'tipo_usuario' esté en el payload del token
}
// Función para obtener todos los usuarios
function obtenerUsuarios($conn) {
    // $sql = "SELECT id, usuario AS correo, nombre, apellidos, idTipoUsuario, celular, calle, colonia, numero, numero_interior, codigo_postal, estado, ciudad, referencias FROM usuarios";
    $sql = "SELECT id, nombre, apellidos, usuario, clave, idTipoUsuario, celular FROM usuarios";
    $result = $conn->query($sql);

    if (!$result) {
        echo json_encode(["error" => "Error en la consulta SQL: " . $conn->error]);
        return;
    }

    $usuarios = [];
    while ($row = $result->fetch_assoc()) {
        $usuarios[] = $row;
    }

    echo json_encode($usuarios);
}

// Función para crear un nuevo usuario
function crearUsuario($conn) {
    $data = json_decode(file_get_contents("php://input"));

    if (isset($data->correo, $data->nombre, $data->apellidos, $data->idTipoUsuario, $data->celular, $data->calle, $data->colonia, $data->numero, $data->codigo_postal, $data->estado, $data->ciudad, $data->referencias)) {

        $correo = $data->correo;
        $nombre = $data->nombre;
        $apellidos = $data->apellidos;
        $clave = password_hash($data->clave, PASSWORD_DEFAULT);
        $idTipoUsuario = $data->idTipoUsuario;
        $celular = $data->celular;


        if (empty($correo)) {
            echo json_encode(["error" => "El campo 'correo' es obligatorio"]);
            return;
        }
        if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(["error" => "El formato del correo electrónico no es válido"]);
            return;
        }
        if (empty($nombre)) {
            echo json_encode(["error" => "El campo 'nombre' es obligatorio"]);
            return;
        }
        if (empty($apellidos)) {
            echo json_encode(["error" => "El campo 'apellidos' es obligatorio"]);
            return;
        }
        if (empty($idTipoUsuario)) {
            echo json_encode(["error" => "El campo 'tipo de usuario' es obligatorio"]);
            return;
        }
        if (empty($celular)) {
            echo json_encode(["error" => "El campo 'celular' es obligatorio"]);
            return;
        }
    

        $sqlVerificar = "SELECT id FROM usuarios WHERE usuario = ?";
        $stmt = $conn->prepare($sqlVerificar);
        $stmt->bind_param("s", $correo);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            echo json_encode(["error" => "El correo ya está registrado."]);
            return;
        }
        

        $sql = "INSERT INTO usuarios (usuario, clave, nombre, apellidos, idTipoUsuario, celular)
                VALUES ('$correo', '$clave', '$nombre', '$apellidos', '$idTipoUsuario', '$celular')";

        if ($conn->query($sql) === TRUE) {
            echo json_encode(["registrado" => true]);
        } else {
            echo json_encode(["error" => $conn->error]);
        }
    }else{
        echo json_encode(["error" => "Datos incompletos"]);
    }
}

// Función para actualizar un usuario existente
function actualizarUsuario($conn) {
    parse_str($_SERVER['QUERY_STRING'], $query);
    $id = $query['id'];

    $data = json_decode(file_get_contents("php://input"));

    if (isset($data->usuario, $data->nombre, $data->apellidos, $data->idTipoUsuario, $data->celular)) {
        $correo = $data->usuario;
        $nombre = $data->nombre;
        $apellidos = $data->apellidos;
        $idTipoUsuario = $data->idTipoUsuario;
        $celular = $data->celular;
       

        // Validaciones de los datos
        if (empty($correo)) {
            echo json_encode(["error" => "El campo 'correo' es obligatorio"]);
            return;
        }
        if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(["error" => "El formato del correo electrónico no es válido"]);
            return;
        }
        if (empty($nombre)) {
            echo json_encode(["error" => "El campo 'nombre' es obligatorio"]);
            return;
        }
        if (empty($apellidos)) {
            echo json_encode(["error" => "El campo 'apellidos' es obligatorio"]);
            return;
        }
        if (empty($idTipoUsuario)) {
            echo json_encode(["error" => "El campo 'tipo de usuario' es obligatorio"]);
            return;
        }
        if (empty($celular)) {
            echo json_encode(["error" => "El campo 'celular' es obligatorio"]);
            return;
        }
      
        // Verificar si el correo ya está registrado por otro usuario
        $sqlVerificar = "SELECT id FROM usuarios WHERE usuario = ? AND id != ?";
        $stmt = $conn->prepare($sqlVerificar);
        $stmt->bind_param("si", $correo, $id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            echo json_encode(["error" => "El correo ya está registrado por otro usuario."]);
            return;
        }

        // Actualizar los datos del usuario
        $sql = $conn->prepare("UPDATE usuarios SET usuario = ?, nombre = ?, apellidos = ?, idTipoUsuario = ?, celular = ? WHERE id = ?");
        $sql->bind_param("sssisi", $correo, $nombre, $apellidos, $idTipoUsuario, $celular, $id);

        if ($sql->execute()) {
            echo json_encode(["actualizado" => true]);
        } else {
            echo json_encode(["error" => $conn->error]);
        }
    } else {
        echo json_encode(["error" => "Datos incompletos"]);
    }
}



// Función para eliminar un usuario
function eliminarUsuario($conn) {
    parse_str($_SERVER['QUERY_STRING'], $query);
    $id = $query['id'];

    $sql = "DELETE FROM usuarios WHERE id = $id";

    if ($conn->query($sql) === TRUE) {
        echo json_encode(["eliminado" => true]);
    } else {
        echo json_encode(["error" => $conn->error]);
    }
}
?>
