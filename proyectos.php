<?php
// require_once 'conexion.php';

header("Access-Control-Allow-Origin: *");  
header("Access-Control-Allow-Headers: Authorization, Content-Type, Access-Control-Allow-Headers, X-Requested-With");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");  
header("Access-Control-Allow-Credentials: true");  


// Manejar las solicitudes preflight (OPTIONS)
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}


require 'auth_helpers.php';
require 'decode.php';

$headers = apache_request_headers();
$jwt = isset($headers['Authorization']) ? str_replace('Bearer ', '', $headers['Authorization']) : "";

if ($jwt && validateToken($jwt)) {
     $user_id = extractToken($jwt);
     $tipo_usuario = extractUserType($jwt);
 
    $method = $_SERVER['REQUEST_METHOD'];
    $input = json_decode(file_get_contents("php://input"), true);

    include "conectar.php";
    $conn = conectarDB();
    
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
    

    switch ($method) {
        case 'GET':
            verificarAcceso('Administrador', $tipo_usuario);
            obtenerProyectos($conn);
            break;
        case 'POST':
            verificarAcceso('Administrador', $tipo_usuario);
            crearProyecto($conn);
            break;
        case 'PUT':
            verificarAcceso('Administrador', $tipo_usuario);
            editarProyecto($conn);
            break;
        case 'DELETE':
            verificarAcceso('Administrador', $tipo_usuario);
            eliminarProyecto($conn);
            break;
        default:
            echo json_encode(["error" => "Método no permitido"]);
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
function extractUserType($jwt) {
    $decoded = decodeToken($jwt); 
    return $decoded['data']->idTipoUsuario ?? null; 
}

// Obtener todos los proyectos con sus usuarios y trámites
function obtenerProyectos($conn) {
    try {
        // Consulta para obtener los proyectos básicos
        $sqlProyectos = "SELECT p.id, p.nombre, p.porcentaje_real, p.porcentaje_avance, p.url_imagen 
                         FROM proyectos p";
        
        $resultProyectos = $conn->query($sqlProyectos);

        if (!$resultProyectos) {
            throw new Exception("Error al obtener proyectos: " . $conn->error);
        }

        $proyectos = [];
        while ($proyecto = $resultProyectos->fetch_assoc()) {
            $idProyecto = $proyecto['id'];
            
            // 1. Obtener usuarios asociados al proyecto
            $sqlUsuarios = "SELECT u.id, u.nombre, u.usuario 
                           FROM proyecto_usuarios pu
                           JOIN usuarios u ON pu.usuario_id = u.id
                           WHERE pu.proyecto_id = ?";
            
            $stmtUsuarios = $conn->prepare($sqlUsuarios);
            $stmtUsuarios->bind_param("i", $idProyecto);
            $stmtUsuarios->execute();
            $resultUsuarios = $stmtUsuarios->get_result();
            $usuarios = $resultUsuarios->fetch_all(MYSQLI_ASSOC);
            $stmtUsuarios->close();

            // 2. Obtener trámites asociados al proyecto desde catalogo_tramites
            $sqlTramites = "SELECT ct.id, ct.nombre, ct.orden
                           FROM proyecto_tramites pt
                           JOIN catalogo_tramites ct ON pt.tramite_id = ct.id
                           WHERE pt.proyecto_id = ?";
            
            $stmtTramites = $conn->prepare($sqlTramites);
            $stmtTramites->bind_param("i", $idProyecto);
            $stmtTramites->execute();
            $resultTramites = $stmtTramites->get_result();
            $tramites = $resultTramites->fetch_all(MYSQLI_ASSOC);
            $stmtTramites->close();

            // 3. Obtener requisitos para cada trámite desde tramite_requisitos
            $tramitesConRequisitos = [];
            foreach ($tramites as $tramite) {
                $idTramite = $tramite['id'];
                
                // Primero obtenemos los IDs de los requisitos
                $sqlRequisitosIds = "SELECT requisito_id 
                                    FROM tramite_requisitos 
                                    WHERE tramite_id = ?";
                
                $stmtRequisitosIds = $conn->prepare($sqlRequisitosIds);
                $stmtRequisitosIds->bind_param("i", $idTramite);
                $stmtRequisitosIds->execute();
                $resultRequisitosIds = $stmtRequisitosIds->get_result();
                $requisitosIds = $resultRequisitosIds->fetch_all(MYSQLI_ASSOC);
                $stmtRequisitosIds->close();

                // Luego obtenemos los detalles de cada requisito
                $requisitosDetalles = [];
                foreach ($requisitosIds as $reqId) {
                    $sqlRequisito = "SELECT id, nombre, orden
                                   FROM catalogo_tramites
                                   WHERE id = ?";
                    
                    $stmtRequisito = $conn->prepare($sqlRequisito);
                    $stmtRequisito->bind_param("i", $reqId['requisito_id']);
                    $stmtRequisito->execute();
                    $resultRequisito = $stmtRequisito->get_result();
                    $requisito = $resultRequisito->fetch_assoc();
                    $stmtRequisito->close();
                    
                    if ($requisito) {
                        $requisitosDetalles[] = $requisito;
                    }
                }

                $tramite['requisitos'] = $requisitosDetalles;
                $tramitesConRequisitos[] = $tramite;
            }

            // Construir el objeto proyecto completo
            $proyectos[] = [
                'id' => $proyecto['id'],
                'nombre' => $proyecto['nombre'],
                'porcentaje_real' => $proyecto['porcentaje_real'],
                'porcentaje_avance' => $proyecto['porcentaje_avance'],
                'url_imagen' => $proyecto['url_imagen'],
                'usuarios' => $usuarios,
                'tramites' => $tramitesConRequisitos
            ];
        }

        echo json_encode([
            'success' => true,
            'data' => $proyectos
        ]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
}

function crearProyecto($conn) {
    // Iniciar transacción
    $conn->begin_transaction();

    try {
        // Obtener datos del proyecto
        $data = json_decode(file_get_contents("php://input"), true);

        // Validaciones obligatorias
        if (empty($data['nombre'])) {
            throw new Exception("El nombre del proyecto es obligatorio");
        }

        if (!isset($data['porcentajeReal']) || !is_numeric($data['porcentajeReal']) || 
            $data['porcentajeReal'] < 0 || $data['porcentajeReal'] > 100) {
            throw new Exception("El porcentaje real debe ser un número entre 0 y 100");
        }

        if (empty($data['usuarios']) || !is_array($data['usuarios'])) {
            throw new Exception("Debe asignar al menos un cliente/usuario al proyecto");
        }

        if (empty($data['tramites']) || !is_array($data['tramites'])) {
            throw new Exception("Debe asignar al menos un trámite al proyecto");
        }

        $nombreProyecto = $data['nombre'];
        $usuarios = $data['usuarios']; // Array de IDs de usuarios (validado que no está vacío)
        $tramites = $data['tramites']; // Array de IDs de trámites (validado que no está vacío)
        $imagen = $data['imagen'] ?? null; // Base64 de la imagen (opcional)
        $porcentajeReal = $data['porcentajeReal'];

        // 1. Crear el proyecto en la base de datos
        $urlImagen = null;
        
        // Manejo de imagen con verificación de permisos
        if ($imagen) {
            $carpetaImagenes = 'imagenes_proyecto';
            crearDirectorioConPermisos($carpetaImagenes);
            
            $nombreImagen = uniqid('proyecto_') . '.jpg';
            $rutaImagen = $carpetaImagenes . '/' . $nombreImagen;
            $imagenData = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $imagen));
            
            if (!file_put_contents($rutaImagen, $imagenData)) {
                throw new Exception("Error al guardar la imagen: " . error_get_last()['message']);
            }
            $urlImagen = $rutaImagen;
        }
        
        // Insertar proyecto
        $sqlInsertProyecto = "INSERT INTO proyectos (nombre, url_imagen, porcentaje_real) VALUES (?, ?, ?)";
        $stmtProyecto = $conn->prepare($sqlInsertProyecto);
        $stmtProyecto->bind_param("ssd", $nombreProyecto, $urlImagen, $porcentajeReal);
        $stmtProyecto->execute();
        $idProyecto = $stmtProyecto->insert_id;
        $stmtProyecto->close();

        // 2. Asignar usuarios al proyecto (validado que hay al menos uno)
        $sqlUsuarios = "INSERT INTO proyecto_usuarios (proyecto_id, usuario_id) VALUES (?, ?)";
        $stmtUsuarios = $conn->prepare($sqlUsuarios);
        
        foreach ($usuarios as $usuarioId) {
            $stmtUsuarios->bind_param("ii", $idProyecto, $usuarioId);
            $stmtUsuarios->execute();
        }
        $stmtUsuarios->close();

        // 3. Asignar trámites al proyecto (validado que hay al menos uno)
        $sqlTramite = "INSERT INTO proyecto_tramites (proyecto_id, tramite_id) VALUES (?, ?)";
        $stmtTramite = $conn->prepare($sqlTramite);
        
        foreach ($tramites as $tramiteId) {
            $stmtTramite->bind_param("ii", $idProyecto, $tramiteId);
            $stmtTramite->execute();
        }
        $stmtTramite->close();

    
         // 4. Crear estructura de carpetas con manejo de errores mejorado
         $carpetaGeneral = 'archivos';
         crearDirectorioConPermisos($carpetaGeneral);
 
         $nombreCarpetaProyecto = $carpetaGeneral . '/' . $idProyecto . '_' . $nombreProyecto;
         crearDirectorioConPermisos($nombreCarpetaProyecto);
 
         // 5. Crear carpetas para trámites y requisitos
         $stmtArchivoTramite = $conn->prepare("INSERT INTO archivos (proyecto_id, tramite_id, url, tipo) VALUES (?, ?, ?, 'tramite')");
         $stmtRequisitos = $conn->prepare("SELECT requisito_id FROM tramite_requisitos WHERE tramite_id = ?");
         $stmtArchivoRequisito = $conn->prepare("INSERT INTO archivos (proyecto_id, tramite_id, requisito_id, url, tipo) VALUES (?, ?, ?, ?, 'requisito')");
 
         foreach ($tramites as $tramiteId) {
             // Crear carpeta para trámite
             $carpetaTramite = $nombreCarpetaProyecto . '/tramite_' . $tramiteId;
             crearDirectorioConPermisos($carpetaTramite);
             
             // Registrar en archivos
             $stmtArchivoTramite->bind_param("iis", $idProyecto, $tramiteId, $carpetaTramite);
             $stmtArchivoTramite->execute();
 
             // Procesar requisitos
             $stmtRequisitos->bind_param("i", $tramiteId);
             $stmtRequisitos->execute();
             $result = $stmtRequisitos->get_result();
             
             while ($requisito = $result->fetch_assoc()) {
                 $requisitoId = $requisito['requisito_id'];
                 $carpetaRequisito = $carpetaTramite . '/requisito_' . $requisitoId;
                 crearDirectorioConPermisos($carpetaRequisito);
                 
                 $stmtArchivoRequisito->bind_param("iiis", $idProyecto, $tramiteId, $requisitoId, $carpetaRequisito);
                 $stmtArchivoRequisito->execute();
             }
         }
 
         $conn->commit();
        
        echo json_encode([
            "success" => true,
            "proyecto_id" => $idProyecto,
            "imagen_url" => $urlImagen,
            "usuarios_asignados" => count($usuarios),
            "tramites_asignados" => count($tramites),
            "message" => "Proyecto creado con trámites y sus requisitos correspondientes"
        ]);

    } catch (Exception $e) {
        // Revertir en caso de error
        $conn->rollback();
        http_response_code(400); // Bad Request
        echo json_encode([
            "error" => true,
            "message" => $e->getMessage()
        ]);
    }
}
function crearDirectorioConPermisos($path) {
    try {
        if (!file_exists($path)) {
            if (!mkdir($path, 0777, true)) {
                throw new Exception("Error creando directorio: $path. " . error_get_last()['message']);
            }
            // Forzar permisos en Windows
            if (!chmod($path, 0777)) {
                throw new Exception("Error asignando permisos a: $path. " . error_get_last()['message']);
            }
        }
        return true;
    } catch (Exception $e) {
        throw new Exception("Error en sistema de archivos: " . $e->getMessage());
    }
}


function editarProyecto($conn) {
    // Iniciar transacción
    $conn->begin_transaction();

    try {
        // Obtener el ID del proyecto desde los query params
        $idProyecto = $_GET['id'] ?? null;
        if (!$idProyecto) {
            throw new Exception("Falta el ID del proyecto en los query params.");
        }

        // Obtener los datos del cuerpo de la solicitud (JSON)
        $data = json_decode(file_get_contents("php://input"), true);
        $nombreProyecto = (string)($data['nombre'] ?? '');
        $usuarios = $data['usuarios'] ?? [];
        $tramites = $data['tramites'] ?? [];
        $porcentajeReal = (float)($data['porcentajeReal'] ?? 0);
        $imagen = $data['imagen'] ?? null;

        // Validaciones básicas
        if (empty($nombreProyecto)) {
            throw new Exception("El nombre del proyecto es obligatorio.");
        }

        if (empty($usuarios) || !is_array($usuarios)) {
            throw new Exception("Debe asignar al menos un usuario al proyecto.");
        }

        if ($porcentajeReal < 0 || $porcentajeReal > 100) {
            throw new Exception("El porcentaje debe estar entre 0 y 100.");
        }

        // 1. Verificar que el proyecto existe y obtener datos actuales
        $sqlCheckProyecto = "SELECT nombre, url_imagen FROM proyectos WHERE id = ?";
        $stmtCheckProyecto = $conn->prepare($sqlCheckProyecto);
        $stmtCheckProyecto->bind_param("i", $idProyecto);
        $stmtCheckProyecto->execute();
        $resultado = $stmtCheckProyecto->get_result();
        $proyectoActual = $resultado->fetch_assoc();
        $stmtCheckProyecto->close();

        if (!$proyectoActual) {
            throw new Exception("No se encontró el proyecto con el ID proporcionado.");
        }

        // 2. Obtener trámites actuales del proyecto para comparación
        $sqlTramitesActuales = "SELECT tramite_id FROM proyecto_tramites WHERE proyecto_id = ?";
        $stmtTramitesActuales = $conn->prepare($sqlTramitesActuales);
        $stmtTramitesActuales->bind_param("i", $idProyecto);
        $stmtTramitesActuales->execute();
        $resultTramitesActuales = $stmtTramitesActuales->get_result();
        $tramitesActuales = $resultTramitesActuales->fetch_all(MYSQLI_ASSOC);
        $stmtTramitesActuales->close();

        $tramitesActualesIds = array_column($tramitesActuales, 'tramite_id');
        $tramitesEliminados = array_diff($tramitesActualesIds, $tramites);

        // 3. Eliminar carpetas y registros de trámites que ya no están asignados
        foreach ($tramitesEliminados as $tramiteId) {
            // Eliminar archivos de la base de datos primero
            $sqlDeleteArchivos = "DELETE FROM archivos WHERE proyecto_id = ? AND tramite_id = ?";
            $stmtDeleteArchivos = $conn->prepare($sqlDeleteArchivos);
            $stmtDeleteArchivos->bind_param("ii", $idProyecto, $tramiteId);
            $stmtDeleteArchivos->execute();
            $stmtDeleteArchivos->close();

            // Eliminar carpeta del trámite
            $carpetaTramite = "archivos/{$idProyecto}_{$proyectoActual['nombre']}/tramite_{$tramiteId}";
            if (file_exists($carpetaTramite)) {
                // Función recursiva para eliminar directorio y su contenido
                $eliminarDirectorio = function($dir) use (&$eliminarDirectorio) {
                    if (!file_exists($dir)) return true;
                    if (!is_dir($dir)) return unlink($dir);
                    
                    foreach (scandir($dir) as $item) {
                        if ($item == '.' || $item == '..') continue;
                        if (!$eliminarDirectorio($dir . DIRECTORY_SEPARATOR . $item)) return false;
                    }
                    
                    return rmdir($dir);
                };
                
                if (!$eliminarDirectorio($carpetaTramite)) {
                    throw new Exception("Error al eliminar la carpeta del trámite {$tramiteId}");
                }
            }
        }

        // 4. Manejo de imagen (si se proporcionó una nueva)
        $urlImagen = $proyectoActual['url_imagen'];
        if ($imagen) {
            $carpetaImagenes = 'imagenes_proyecto';
            if (!file_exists($carpetaImagenes)) {
                mkdir($carpetaImagenes, 0777, true);
            }
            
            if ($urlImagen && file_exists($urlImagen)) {
                unlink($urlImagen);
            }
            
            $nombreImagen = uniqid('proyecto_') . '.jpg';
            $rutaImagen = $carpetaImagenes . '/' . $nombreImagen;
            $imagenData = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $imagen));
            file_put_contents($rutaImagen, $imagenData);
            $urlImagen = $rutaImagen;
        }

        // 5. Actualizar proyecto en la base de datos
        $sqlUpdateProyecto = "UPDATE proyectos SET nombre = ?, porcentaje_real = ?, url_imagen = ? WHERE id = ?";
        $stmtProyecto = $conn->prepare($sqlUpdateProyecto);
        $stmtProyecto->bind_param("sdsi", $nombreProyecto, $porcentajeReal, $urlImagen, $idProyecto);
        $stmtProyecto->execute();
        $stmtProyecto->close();

        // 6. Actualizar usuarios asignados
        $sqlDeleteUsuarios = "DELETE FROM proyecto_usuarios WHERE proyecto_id = ?";
        $stmtDeleteUsuarios = $conn->prepare($sqlDeleteUsuarios);
        $stmtDeleteUsuarios->bind_param("i", $idProyecto);
        $stmtDeleteUsuarios->execute();
        $stmtDeleteUsuarios->close();

        if (!empty($usuarios)) {
            $sqlInsertUsuarios = "INSERT INTO proyecto_usuarios (proyecto_id, usuario_id) VALUES (?, ?)";
            $stmtInsertUsuarios = $conn->prepare($sqlInsertUsuarios);
            
            foreach ($usuarios as $usuarioId) {
                $stmtInsertUsuarios->bind_param("ii", $idProyecto, $usuarioId);
                $stmtInsertUsuarios->execute();
            }
            $stmtInsertUsuarios->close();
        }

        // 7. Actualizar trámites asignados
        $sqlDeleteTramites = "DELETE FROM proyecto_tramites WHERE proyecto_id = ?";
        $stmtDeleteTramites = $conn->prepare($sqlDeleteTramites);
        $stmtDeleteTramites->bind_param("i", $idProyecto);
        $stmtDeleteTramites->execute();
        $stmtDeleteTramites->close();

        if (!empty($tramites)) {
            $sqlInsertTramites = "INSERT INTO proyecto_tramites (proyecto_id, tramite_id) VALUES (?, ?)";
            $stmtInsertTramites = $conn->prepare($sqlInsertTramites);
            
            foreach ($tramites as $tramiteId) {
                $stmtInsertTramites->bind_param("ii", $idProyecto, $tramiteId);
                $stmtInsertTramites->execute();
            }
            $stmtInsertTramites->close();
        }

        // 8. Renombrar carpeta principal si cambió el nombre
        $carpetaGeneral = 'archivos';
        $nombreAnterior = $proyectoActual['nombre'];
        $nombreCarpetaProyectoAnterior = "{$carpetaGeneral}/{$idProyecto}_{$nombreAnterior}";
        $nombreCarpetaProyectoNuevo = "{$carpetaGeneral}/{$idProyecto}_{$nombreProyecto}";

        if (file_exists($nombreCarpetaProyectoAnterior) && $nombreAnterior !== $nombreProyecto) {
            if (!rename($nombreCarpetaProyectoAnterior, $nombreCarpetaProyectoNuevo)) {
                throw new Exception("Error al renombrar la carpeta del proyecto.");
            }

            $sqlUpdateArchivo = "UPDATE archivos SET url = REPLACE(url, ?, ?) WHERE proyecto_id = ?";
            $stmtArchivo = $conn->prepare($sqlUpdateArchivo);
            $stmtArchivo->bind_param("ssi", $nombreCarpetaProyectoAnterior, $nombreCarpetaProyectoNuevo, $idProyecto);
            $stmtArchivo->execute();
            $stmtArchivo->close();
        }

        // 9. Crear carpetas para nuevos trámites
        $nuevosTramites = array_diff($tramites, $tramitesActualesIds);
        foreach ($nuevosTramites as $tramiteId) {
            $carpetaTramite = "{$nombreCarpetaProyectoNuevo}/tramite_{$tramiteId}";
            if (!file_exists($carpetaTramite)) {
                mkdir($carpetaTramite, 0777, true);
                
                // Registrar en tabla archivos
                $sqlArchivo = "INSERT INTO archivos (proyecto_id, tramite_id, url, tipo) VALUES (?, ?, ?, 'tramite')";
                $stmtArchivo = $conn->prepare($sqlArchivo);
                $stmtArchivo->bind_param("iis", $idProyecto, $tramiteId, $carpetaTramite);
                $stmtArchivo->execute();
                $stmtArchivo->close();
            }
        }

        // Confirmar transacción
        $conn->commit();
        
        echo json_encode([
            "success" => true,
            "message" => "Proyecto actualizado correctamente.",
            "proyecto_id" => $idProyecto,
            "nombre" => $nombreProyecto,
            "usuarios" => $usuarios,
            "tramites" => $tramites,
            "tramites_eliminados" => array_values($tramitesEliminados),
            "tramites_nuevos" => array_values($nuevosTramites)
        ]);

    } catch (Exception $e) {
        // Revertir en caso de error
        $conn->rollback();
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "error" => $e->getMessage()
        ]);
    }
}

function eliminarProyecto($conn) {
    // Iniciar transacción
    $conn->begin_transaction();

    try {
        // Obtener el ID del proyecto desde los query params
        $idProyecto = $_GET['id'] ?? null;
        if (!$idProyecto) {
            throw new Exception("Falta el ID del proyecto.");
        }

        // Verificar que el proyecto existe y obtener el nombre y la imagen
        $sql = "SELECT nombre, url_imagen FROM proyectos WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $idProyecto);
        $stmt->execute();
        $result = $stmt->get_result();
        $proyecto = $result->fetch_assoc();
        $stmt->close();

        if (!$proyecto) {
            throw new Exception("Proyecto no encontrado.");
        }

        $nombreProyecto = $proyecto['nombre'];
        $urlImagen = $proyecto['url_imagen'];

        // 1. Eliminar registros relacionados
        $stmt = $conn->prepare("DELETE FROM archivos WHERE proyecto_id = ?");
        $stmt->bind_param("i", $idProyecto);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("DELETE FROM proyecto_tramites WHERE proyecto_id = ?");
        $stmt->bind_param("i", $idProyecto);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("DELETE FROM proyecto_usuarios WHERE proyecto_id = ?");
        $stmt->bind_param("i", $idProyecto);
        $stmt->execute();
        $stmt->close();

        // 2. Eliminar el proyecto
        $stmt = $conn->prepare("DELETE FROM proyectos WHERE id = ?");
        $stmt->bind_param("i", $idProyecto);
        $stmt->execute();
        $stmt->close();

        // 3. Eliminar carpeta de archivos del proyecto
        $carpetaProyecto = "archivos/{$idProyecto}_{$nombreProyecto}";
        $eliminarDirectorio = function($dir) use (&$eliminarDirectorio) {
            if (!file_exists($dir)) return true;
            if (!is_dir($dir)) return unlink($dir);
            foreach (scandir($dir) as $item) {
                if ($item == '.' || $item == '..') continue;
                if (!$eliminarDirectorio($dir . DIRECTORY_SEPARATOR . $item)) return false;
            }
            return rmdir($dir);
        };

        if (file_exists($carpetaProyecto) && !$eliminarDirectorio($carpetaProyecto)) {
            throw new Exception("No se pudo eliminar la carpeta del proyecto.");
        }

        // 4. Eliminar imagen si existe
        if ($urlImagen && file_exists($urlImagen)) {
            unlink($urlImagen);
        }

        // Confirmar transacción
        $conn->commit();

        echo json_encode([
            "success" => true,
            "message" => "Proyecto eliminado correctamente."
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "error" => $e->getMessage()
        ]);
    }
}

// function eliminarCarpeta($carpeta) {
//     if (!is_dir($carpeta)) {
//         return false; // No es una carpeta válida
//     }

//     $archivos = array_diff(scandir($carpeta), array('..', '.')); // Obtiene el contenido

//     foreach ($archivos as $archivo) {
//         $archivoPath = $carpeta . DIRECTORY_SEPARATOR . $archivo;

//         if (is_dir($archivoPath)) {
//             eliminarCarpeta($archivoPath); // Llamada recursiva para subdirectorios
//         } else {
//             unlink($archivoPath); // Eliminar archivos
//         }
//     }

//     return rmdir($carpeta); // Finalmente elimina la carpeta vacía
// }

// function eliminarProyecto($conn) {
//     // Obtener el ID del proyecto desde los query params
//     $idProyecto = $_GET['id'] ?? null;

//     if (!$idProyecto) {
//         echo json_encode(["error" => "Falta el ID del proyecto en los query params."]);
//         return;
//     }

//     // 1. Obtener el nombre y usuario_id del proyecto
//     $carpetaGeneral = 'archivos';
//     $sqlCarpeta = "SELECT nombre, usuario_id FROM proyectos WHERE id = ?";
//     $stmtCarpeta = $conn->prepare($sqlCarpeta);
//     if (!$stmtCarpeta) {
//         echo json_encode(["error" => "Error al preparar la consulta para obtener el nombre y usuario_id del proyecto: " . $conn->error]);
//         return;
//     }

//     $stmtCarpeta->bind_param("i", $idProyecto);
//     $stmtCarpeta->execute();
//     $stmtCarpeta->bind_result($nombreProyecto, $usuarioId);
//     $stmtCarpeta->fetch();
//     $stmtCarpeta->close();

//     // Validar que el proyecto existe
//     if (!$nombreProyecto || !$usuarioId) {
//         echo json_encode(["error" => "El proyecto con ID $idProyecto no existe."]);
//         return;
//     }

//     // Construir la ruta de la carpeta del proyecto
//     $nombreCarpetaProyecto = "{$carpetaGeneral}/{$idProyecto}_{$usuarioId}_{$nombreProyecto}";

//     // 2. Eliminar la carpeta del proyecto y sus archivos
//     if (file_exists($nombreCarpetaProyecto)) {
//         if (!eliminarCarpeta($nombreCarpetaProyecto)) {
//             echo json_encode(["error" => "Error al eliminar la carpeta del proyecto."]);
//             return;
//         }
//     } else {
//         echo json_encode(["error" => "La carpeta del proyecto no existe: {$nombreCarpetaProyecto}"]);
//         return;
//     }

//     // 3. Eliminar los registros de archivos asociados en la base de datos
//     $sqlEliminarArchivos = "DELETE FROM archivos WHERE proyecto_id = ?";
//     $stmtEliminarArchivos = $conn->prepare($sqlEliminarArchivos);
//     if (!$stmtEliminarArchivos) {
//         echo json_encode(["error" => "Error al preparar la consulta para eliminar los archivos: " . $conn->error]);
//         return;
//     }

//     $stmtEliminarArchivos->bind_param("i", $idProyecto);
//     if (!$stmtEliminarArchivos->execute()) {
//         echo json_encode(["error" => "Error al eliminar los archivos del proyecto: " . $stmtEliminarArchivos->error]);
//         return;
//     }
//     $stmtEliminarArchivos->close();

//     // 4. Eliminar el proyecto de la base de datos
//     $sqlEliminarProyecto = "DELETE FROM proyectos WHERE id = ?";
//     $stmtEliminarProyecto = $conn->prepare($sqlEliminarProyecto);
//     if (!$stmtEliminarProyecto) {
//         echo json_encode(["error" => "Error al preparar la consulta para eliminar el proyecto: " . $conn->error]);
//         return;
//     }

//     $stmtEliminarProyecto->bind_param("i", $idProyecto);
//     if (!$stmtEliminarProyecto->execute()) {
//         echo json_encode(["error" => "Error al eliminar el proyecto: " . $stmtEliminarProyecto->error]);
//         return;
//     }
//     $stmtEliminarProyecto->close();

//     echo json_encode(["success" => "Proyecto y archivos eliminados con éxito."]);
// }









