CREATE TABLE llx_receiptprinterextended_profile
(
    rowid       INTEGER AUTO_INCREMENT PRIMARY KEY,
    entity      INTEGER DEFAULT 1 NOT NULL,
    ref         VARCHAR(64) NOT NULL,
    endpoint    VARCHAR(255) DEFAULT '' NOT NULL,
    token       VARCHAR(255) DEFAULT '' NOT NULL,
    timeout     INTEGER DEFAULT 0 NOT NULL,
    verify_ssl  INTEGER DEFAULT -1 NOT NULL,
    datec       DATETIME NULL,
    tms         TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
