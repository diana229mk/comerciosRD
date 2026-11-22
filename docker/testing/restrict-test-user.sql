REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'comercios_storage_test'@'%';

GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES
ON `comercios_rd_storage_test`.*
TO 'comercios_storage_test'@'%';
