<?php
declare(strict_types=1);

namespace amblydia\databaseapi\helper;

use PDO;

final class ConnectionHelper {

    /**
     * @param array $credentials
     * @return PDO
     */
    public static function connect(array $credentials): PDO {
        if (isset($credentials["path"])) {
            return new PDO("sqlite:" . $credentials["path"]);
        }

        $dsn = "mysql:host={$credentials['host']};dbname={$credentials['dbname']};port={$credentials['port']};charset=utf8mb4";

        $usr = $credentials["username"];
        $pwd = $credentials["password"];

        return new PDO($dsn, $usr, $pwd, [PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true]);
    }
}