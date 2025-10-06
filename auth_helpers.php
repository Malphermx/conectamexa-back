<?php
require 'vendor/autoload.php';
require 'config.php';
use \Firebase\JWT\JWT;
use \Firebase\JWT\Key;

define('SECRET_KEY', $secret_key);

// Validar un token JWT
function validateToken($jwt) {
    try {
        $decoded = JWT::decode($jwt, new Key(SECRET_KEY, 'HS256'));
        return true;
    } catch (Exception $e) {
        return false;
    }
}

// Extraer información del token JWT
function extractToken($jwt) {
    try {
        $decoded = JWT::decode($jwt, new Key(SECRET_KEY, 'HS256'));
        return $decoded->user_id ?? null;
    } catch (Exception $e) {
        return null;
    }
}
