CREATE TABLE llx_printbridge_log
(
    rowid       INTEGER AUTO_INCREMENT PRIMARY KEY,
    entity      INTEGER DEFAULT 1 NOT NULL,
    datec       DATETIME NOT NULL,
    profile_ref VARCHAR(64) DEFAULT '' NOT NULL,
    endpoint    VARCHAR(255) DEFAULT '' NOT NULL,
    success     INTEGER DEFAULT 0 NOT NULL,
    httpcode    INTEGER DEFAULT 0 NOT NULL,
    size        INTEGER DEFAULT 0 NOT NULL,
    content     MEDIUMTEXT NOT NULL
) ENGINE=innodb;
