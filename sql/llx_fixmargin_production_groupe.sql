-- Copyright (C) 2026 Jeffinfo - Olivier Geffroy <jeff@jeffinfo.com>
-- Table des groupes de production (fût → verres)

CREATE TABLE IF NOT EXISTS llx_fixmargin_production_groupe (
    rowid             INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
    entity            INT          NOT NULL DEFAULT 1,
    label             VARCHAR(255) NOT NULL,
    fk_product_source INT          NOT NULL,
    volume_fut        DECIMAL(10,3) NOT NULL DEFAULT 0,
    fk_warehouse      INT          DEFAULT NULL,
    actif             TINYINT      NOT NULL DEFAULT 1,
    date_creation     DATETIME,
    fk_user_creat     INT          DEFAULT NULL,
    tms               TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_entity (entity),
    INDEX idx_fk_product_source (fk_product_source)
) ENGINE=InnoDB;
