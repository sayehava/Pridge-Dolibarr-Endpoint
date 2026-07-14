CREATE TABLE llx_pridge_profile
(
    rowid           INTEGER AUTO_INCREMENT PRIMARY KEY,
    entity          INTEGER DEFAULT 1 NOT NULL,
    ref             VARCHAR(64) NOT NULL,
    server_id       INTEGER DEFAULT 0 NOT NULL,
    endpoint_token  VARCHAR(255) DEFAULT '' NOT NULL,
    endpoint        VARCHAR(255) DEFAULT '' NOT NULL,
    timeout         INTEGER DEFAULT 0 NOT NULL,
    datec           DATETIME NULL,
    tms             TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
