<?php
/* Copyright (C) 2026 Jeffinfo - Olivier Geffroy <jeff@jeffinfo.com> */

if (!defined('DOL_VERSION')) exit;

/**
 * Groupe de production : associe un produit source (fût) à des produits dérivés (formats)
 */
class FixMarginProductionGroupe
{
    public $id;
    public $rowid;
    public $entity;
    public $label;
    public $fk_product_source;
    public $volume_fut         = 0;
    public $fk_warehouse;
    public $actif              = 1;
    public $date_creation;
    public $fk_user_creat;

    // Joined
    public $product_source_ref;
    public $product_source_label;
    public $warehouse_label;

    /** @var FixMarginProductionDerive[] */
    public $derives = array();

    public $db;
    public $error  = '';
    public $errors = array();

    public function __construct($db) { $this->db = $db; }

    // ─────────────────────────────────────────────
    // GROUPE CRUD
    // ─────────────────────────────────────────────

    public function fetch($id, $with_derives = true)
    {
        $sql  = "SELECT g.*, p.ref as product_source_ref, p.label as product_source_label,";
        $sql .= " e.ref as warehouse_label";
        $sql .= " FROM ".MAIN_DB_PREFIX."fixmargin_production_groupe as g";
        $sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product as p ON p.rowid = g.fk_product_source";
        $sql .= " LEFT JOIN ".MAIN_DB_PREFIX."entrepot as e ON e.rowid = g.fk_warehouse";
        $sql .= " WHERE g.rowid = ".(int)$id;
        $res = $this->db->query($sql);
        if (!$res) { $this->error = $this->db->lasterror(); return -1; }
        $obj = $this->db->fetch_object($res);
        if (!$obj) return 0;
        $this->_mapFromObj($obj);
        if ($with_derives) $this->fetchDerives($this->id);
        return 1;
    }

    /**
     * @return FixMarginProductionGroupe[]|int
     */
    public function fetchAll($entity, $active_only = false)
    {
        $sql  = "SELECT g.*, p.ref as product_source_ref, p.label as product_source_label,";
        $sql .= " e.ref as warehouse_label";
        $sql .= " FROM ".MAIN_DB_PREFIX."fixmargin_production_groupe as g";
        $sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product as p ON p.rowid = g.fk_product_source";
        $sql .= " LEFT JOIN ".MAIN_DB_PREFIX."entrepot as e ON e.rowid = g.fk_warehouse";
        $sql .= " WHERE g.entity = ".(int)$entity;
        if ($active_only) $sql .= " AND g.actif = 1";
        $sql .= " ORDER BY g.label ASC";
        $res = $this->db->query($sql);
        if (!$res) { $this->error = $this->db->lasterror(); return -1; }

        // Collect all rows first to avoid cursor conflict when fetchDerives runs sub-queries
        $rows = array();
        while ($obj = $this->db->fetch_object($res)) {
            $rows[] = $obj;
        }

        $list = array();
        foreach ($rows as $obj) {
            $g = new FixMarginProductionGroupe($this->db);
            $g->_mapFromObj($obj);
            $g->fetchDerives($g->id);
            $list[] = $g;
        }
        return $list;
    }

    public function create($user)
    {
        global $conf;
        $this->db->begin();
        $sql  = "INSERT INTO ".MAIN_DB_PREFIX."fixmargin_production_groupe";
        $sql .= " (entity, label, fk_product_source, volume_fut, fk_warehouse, actif, date_creation, fk_user_creat) VALUES (";
        $sql .= (int)$conf->entity.",";
        $sql .= "'".$this->db->escape($this->label)."',";
        $sql .= (int)$this->fk_product_source.",";
        $sql .= price2num($this->volume_fut).",";
        $sql .= (!empty($this->fk_warehouse) ? (int)$this->fk_warehouse : "NULL").",";
        $sql .= (int)$this->actif.",";
        $sql .= "'".$this->db->idate(dol_now())."',";
        $sql .= (int)$user->id.")";
        if ($this->db->query($sql)) {
            $this->id = $this->rowid = $this->db->last_insert_id(MAIN_DB_PREFIX."fixmargin_production_groupe");
            $this->db->commit();
            return $this->id;
        }
        $this->error = $this->db->lasterror();
        $this->db->rollback();
        return -1;
    }

    public function update($user)
    {
        $this->db->begin();
        $sql  = "UPDATE ".MAIN_DB_PREFIX."fixmargin_production_groupe SET";
        $sql .= " label='".$this->db->escape($this->label)."',";
        $sql .= " fk_product_source=".(int)$this->fk_product_source.",";
        $sql .= " volume_fut=".price2num($this->volume_fut).",";
        $sql .= " fk_warehouse=".(!empty($this->fk_warehouse) ? (int)$this->fk_warehouse : "NULL").",";
        $sql .= " actif=".(int)$this->actif;
        $sql .= " WHERE rowid=".(int)$this->id;
        if ($this->db->query($sql)) { $this->db->commit(); return 1; }
        $this->error = $this->db->lasterror();
        $this->db->rollback();
        return -1;
    }

    public function delete($user)
    {
        $this->db->begin();
        $this->db->query("DELETE FROM ".MAIN_DB_PREFIX."fixmargin_production_derive WHERE fk_groupe=".(int)$this->id);
        $sql = "DELETE FROM ".MAIN_DB_PREFIX."fixmargin_production_groupe WHERE rowid=".(int)$this->id;
        if ($this->db->query($sql)) { $this->db->commit(); return 1; }
        $this->error = $this->db->lasterror();
        $this->db->rollback();
        return -1;
    }

    // ─────────────────────────────────────────────
    // DERIVES CRUD
    // ─────────────────────────────────────────────

    public function fetchDerives($groupe_id)
    {
        $sql  = "SELECT d.*, p.ref as product_ref, p.label as product_label,";
        $sql .= " b.ref as bom_ref";
        $sql .= " FROM ".MAIN_DB_PREFIX."fixmargin_production_derive as d";
        $sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product as p ON p.rowid = d.fk_product";
        $sql .= " LEFT JOIN ".MAIN_DB_PREFIX."bom_bom as b ON b.rowid = d.fk_bom";
        $sql .= " WHERE d.fk_groupe = ".(int)$groupe_id;
        $sql .= " ORDER BY d.rang ASC, d.rowid ASC";
        $res = $this->db->query($sql);
        if (!$res) { $this->error = $this->db->lasterror(); return -1; }
        $this->derives = array();
        while ($obj = $this->db->fetch_object($res)) {
            $d = new FixMarginProductionDerive();
            $d->id              = (int)$obj->rowid;
            $d->fk_groupe       = (int)$obj->fk_groupe;
            $d->fk_product      = (int)$obj->fk_product;
            $d->fk_bom          = $obj->fk_bom ? (int)$obj->fk_bom : null;
            $d->volume_unitaire = (float)$obj->volume_unitaire;
            $d->rang            = (int)$obj->rang;
            $d->actif           = (int)$obj->actif;
            $d->product_ref     = $obj->product_ref;
            $d->product_label   = $obj->product_label;
            $d->bom_ref         = $obj->bom_ref;
            // Calcul qte_max_theorique
            $d->qte_max_theorique = ($d->volume_unitaire > 0 && $this->volume_fut > 0)
                ? floor($this->volume_fut / $d->volume_unitaire)
                : 0;
            $this->derives[] = $d;
        }
        return count($this->derives);
    }

    public function addDerive($user, $fk_product, $fk_bom, $volume_unitaire, $rang = 0, $actif = 1)
    {
        $sql  = "INSERT INTO ".MAIN_DB_PREFIX."fixmargin_production_derive";
        $sql .= " (fk_groupe, fk_product, fk_bom, volume_unitaire, rang, actif) VALUES (";
        $sql .= (int)$this->id.",";
        $sql .= (int)$fk_product.",";
        $sql .= (!empty($fk_bom) ? (int)$fk_bom : "NULL").",";
        $sql .= price2num($volume_unitaire).",";
        $sql .= (int)$rang.",";
        $sql .= (int)$actif.")";
        if ($this->db->query($sql)) return $this->db->last_insert_id(MAIN_DB_PREFIX."fixmargin_production_derive");
        $this->error = $this->db->lasterror();
        return -1;
    }

    public function updateDerive($user, $derive_id, $fk_product, $fk_bom, $volume_unitaire, $rang = 0, $actif = 1)
    {
        $sql  = "UPDATE ".MAIN_DB_PREFIX."fixmargin_production_derive SET";
        $sql .= " fk_product=".(int)$fk_product.",";
        $sql .= " fk_bom=".(!empty($fk_bom) ? (int)$fk_bom : "NULL").",";
        $sql .= " volume_unitaire=".price2num($volume_unitaire).",";
        $sql .= " rang=".(int)$rang.",";
        $sql .= " actif=".(int)$actif;
        $sql .= " WHERE rowid=".(int)$derive_id." AND fk_groupe=".(int)$this->id;
        if ($this->db->query($sql)) return 1;
        $this->error = $this->db->lasterror();
        return -1;
    }

    public function deleteDerive($user, $derive_id)
    {
        $sql = "DELETE FROM ".MAIN_DB_PREFIX."fixmargin_production_derive WHERE rowid=".(int)$derive_id." AND fk_groupe=".(int)$this->id;
        if ($this->db->query($sql)) return 1;
        $this->error = $this->db->lasterror();
        return -1;
    }

    // ─────────────────────────────────────────────
    // STOCK HELPER
    // ─────────────────────────────────────────────

    /**
     * Retourne le stock réel d'un produit (entrepôt optionnel)
     */
    public function getProductStock($fk_product, $fk_warehouse = 0)
    {
        if ($fk_warehouse > 0) {
            $sql = "SELECT reel FROM ".MAIN_DB_PREFIX."product_stock WHERE fk_product=".(int)$fk_product." AND fk_entrepot=".(int)$fk_warehouse;
        } else {
            $sql = "SELECT SUM(reel) as reel FROM ".MAIN_DB_PREFIX."product_stock WHERE fk_product=".(int)$fk_product;
        }
        $res = $this->db->query($sql);
        if ($res) {
            $obj = $this->db->fetch_object($res);
            if ($obj) return (float)$obj->reel;
        }
        return 0;
    }

    /**
     * Vérifie si un MO ouvert (non clôturé) existe déjà pour un produit dérivé
     * @return int 0=aucun, >0=rowid du MO existant
     */
    public function checkExistingOpenMo($fk_product)
    {
        global $conf;
        $sql  = "SELECT rowid FROM ".MAIN_DB_PREFIX."mrp_mo";
        $sql .= " WHERE fk_product=".(int)$fk_product." AND entity=".(int)$conf->entity;
        $sql .= " AND status NOT IN (3, 9)"; // 3=produit, 9=annulé
        $sql .= " LIMIT 1";
        $res = $this->db->query($sql);
        if ($res) {
            $obj = $this->db->fetch_object($res);
            if ($obj) return (int)$obj->rowid;
        }
        return 0;
    }

    // ─────────────────────────────────────────────
    // HELPERS
    // ─────────────────────────────────────────────

    public function _mapFromObj($obj)
    {
        $this->id                   = $this->rowid = (int)$obj->rowid;
        $this->entity               = (int)$obj->entity;
        $this->label                = $obj->label;
        $this->fk_product_source    = (int)$obj->fk_product_source;
        $this->volume_fut           = (float)$obj->volume_fut;
        $this->fk_warehouse         = $obj->fk_warehouse ? (int)$obj->fk_warehouse : null;
        $this->actif                = (int)$obj->actif;
        $this->date_creation        = isset($obj->date_creation) ? $this->db->jdate($obj->date_creation) : null;
        $this->fk_user_creat        = (int)$obj->fk_user_creat;
        $this->product_source_ref   = isset($obj->product_source_ref)   ? $obj->product_source_ref   : '';
        $this->product_source_label = isset($obj->product_source_label) ? $obj->product_source_label : '';
        $this->warehouse_label      = isset($obj->warehouse_label)      ? $obj->warehouse_label      : '';
    }
}

/**
 * Produit dérivé d'un groupe de production (simple objet de données)
 */
class FixMarginProductionDerive
{
    public $id;
    public $fk_groupe;
    public $fk_product;
    public $fk_bom;
    public $volume_unitaire  = 0;
    public $rang             = 0;
    public $actif            = 1;

    // Joined
    public $product_ref;
    public $product_label;
    public $bom_ref;

    // Computed
    public $qte_max_theorique = 0;
}
