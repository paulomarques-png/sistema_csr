<?php
/**
 * Conexão com o banco de dados MySQL
 * Usando PDO para maior segurança e flexibilidade
 */

define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'sistema_carga');
define('DB_USER', 'root');
define('DB_PASS', '');       // Vazio no XAMPP local
define('DB_CHARSET', 'utf8mb4');

function conectar(): PDO {
    static $pdo = null;      // Reutiliza a mesma conexão

    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST
             . ";dbname=" . DB_NAME
             . ";charset=" . DB_CHARSET;

        $opcoes = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $opcoes);
        } catch (PDOException $e) {
            // Em produção, nunca mostre o erro real ao usuário
            error_log("Erro de conexão: " . $e->getMessage());
            die("Erro ao conectar com o banco de dados. Contate o suporte.");
        }
    }

    return $pdo;
}