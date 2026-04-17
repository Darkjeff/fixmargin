-- Copyright (C) 2026 Jeffinfo - Olivier Geffroy <jeff@jeffinfo.com>
-- Table des produits dérivés liés à un groupe de production

CREATE TABLE IF NOT EXISTS llx_fixmargin_production_derive (
    rowid           INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
    fk_groupe       INT          NOT NULL,
    fk_product      INT          NOT NULL,
    fk_bom          INT          DEFAULT NULL,
    volume_unitaire DECIMAL(10,4) NOT NULL DEFAULT 0,
    rang            INT          NOT NULL DEFAULT 0,
    actif           TINYINT      NOT NULL DEFAULT 1,
    INDEX idx_fk_groupe (fk_groupe),
    INDEX idx_fk_product (fk_product)
) ENGINE=InnoDB;
