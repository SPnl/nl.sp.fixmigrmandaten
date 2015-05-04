<?php

/**
 * Class CRM_Fixmigrmandaten_Do
 */
class CRM_Fixmigrmandaten_Do {

	public function execute() {

		return array(
			'migrateAdditionalMandates' => $this->migrateAdditionalMandates(),
			'fixAllFirstMandates' => $this->fixAllFirstMandates(),
		);
	}

	// Tribune / Spanning / donaties: alsnog mandaten migreren en koppelen aan bijdragen
	private function migrateAdditionalMandates() {

		// Parameters
		$updated = 0;
		$mconfig = CRM_Sepamandaat_Config_SepaMandaat::singleton();

		// Haal de relevante lidmaatschappen op = alles wat betaald is behalve SP/ROOD,
		// en die IDs staan op acc/live wel vast
		$memberships = CRM_Core_DAO::executeQuery("
			SELECT cm.* FROM civicrm_membership cm
 			LEFT JOIN civicrm_membership_mandaat cmm ON cmm.entity_id = cm.id
			WHERE cm.membership_type_id IN (4,5,6,10,12)
			AND cmm.mandaat_id IS NULL");
		while($memberships->fetch()) {

			// Probeer bijbehorende lidmaatschap+mandaat-record te vinden in een van de tig SP-data-tabellen (die zo handig kolomnamen hebben)
			// Queries zijn getest, dit levert 1 rij per lidmaatschap op uit sp_data.
			$manywareMandaatId = null;

			switch($memberships->membership_type_id) {

				case 4: // Abonnee Blad-Tribune Betaald
				case 5: // Abonnee Blad-Tribune Proef
				case 6: // Abonnee Audio-Tribune Betaald
					$manywareMandaatId = CRM_Core_DAO::singleValueQuery("
						SELECT `COL 39` FROM sp_data.lidmaatschappen
						WHERE `COL 2` = '" . $memberships->contact_id . "'
						AND `COL 12` = '" . $memberships->join_date . "'
						AND `COL 28` IN ('ABNAC','ABNIN','ABNPO','ABOGR','ABOKA','ABOPR')
					");
					break;

				case 10: // Abonnee SPanning Betaald
					$manywareMandaatId = CRM_Core_DAO::singleValueQuery("
					SELECT `COL 20` FROM sp_data.spanning
					WHERE `COL 2` = '" . $memberships->contact_id . "'
					AND (STR_TO_DATE(`COL 8`,'%d-%m-%Y') LIKE '" . $memberships->join_date . "'
					OR STR_TO_DATE(`COL 3`,'%d-%m-%Y') LIKE '" . $memberships->join_date . "')
					");
					break;

				case 12: // SP Donateur
					$manywareMandaatId = CRM_Core_DAO::singleValueQuery("
					SELECT `COL 29` FROM sp_data.toezeggingen
					WHERE `COL 2` = '" . $memberships->contact_id . "'
					AND STR_TO_DATE(`COL 4`,'%d-%m-%Y') LIKE '" . $memberships->join_date . "'
					");
					break;
			}

			// Als er geen mandaat-ID is, betaalde deze al per incasso oid
			if(!$manywareMandaatId)
				continue;

			// Mandaat uit Manyware opzoeken
			$manywareMandaat = CRM_Core_DAO::executeQuery("
				SELECT * FROM sp_data.mandaten
				WHERE `COL 1` = '" . $manywareMandaatId . "'
				AND `COL 2` = '" . $memberships->contact_id . "'
				");
			if(!$manywareMandaat->fetch() || !$manywareMandaat->COL_16) {
				echo "Geen geldig mandaat voor {$memberships->contact_id}.\n";
				continue;
			}

			// Een nieuw mandaat aanmaken
			$mandaatNr = preg_replace('/(MBOA\-)(0*)([1-9][0-9]*\-)(0*)([1-9][0-9]*)/i', '${1}${3}${5}', $manywareMandaat->COL_15);
			$mandateParams = array(
				'id' => $manywareMandaat->COL_2,
				'custom_'.$mconfig->getCustomField('mandaat_nr', 'id') => $mandaatNr,
				'custom_'.$mconfig->getCustomField('status', 'id') => $manywareMandaat->COL_13,
				'custom_'.$mconfig->getCustomField('IBAN', 'id') => $manywareMandaat->COL_16,
				'custom_'.$mconfig->getCustomField('BIC', 'id') => $manywareMandaat->COL_17,
				'custom_'.$mconfig->getCustomField('mandaat_datum', 'id') => date('Ymdhis', strtotime($manywareMandaat->COL_8)),
			);
			civicrm_api3('Contact', 'create', $mandateParams);

			// Mandaat toevoegen aan het lidmaatschap
			CRM_Core_DAO::executeQuery("REPLACE INTO civicrm_membership_mandaat SET entity_id = '" . $memberships->id . "', mandaat_id = '" . $mandaatNr . "'");

			// De relevante bijdragen opzoeken en het mandaat toevoegen
			$contributions = CRM_Core_DAO::executeQuery("
				SELECT * FROM civicrm_membership_payment cmp
				LEFT JOIN civicrm_contribution cc ON cmp.contribution_id = cc.id
				WHERE cmp.membership_id = '" . $memberships->id . "'
				AND cc.payment_instrument_id = 10
				");
			while($contributions->fetch()) {
				CRM_Core_DAO::executeQuery("REPLACE INTO civicrm_contribution_mandaat SET entity_id = '" . $contributions->id . "', mandaat_id = '" . $mandaatNr . "'");
			}

			echo "migrateAdditionalMandates: created mandate {$mandaatNr} for contact {$memberships->contact_id}.\n";
			$updated++;
		}

		return "migrateAdditionalMandates: imported {$updated} mandates.";
	}

	/**
	 * We wilden:
	 * - Alle SP+ROOD-leden een nieuw FRST-mandaat geven ipv het ongebruikte NEW-mandaat
	 * - Voor de zekerheid alle SP-leden met een FRST-mandaat ook een nieuwe ID geven, en NEW corrigeren naar FRST
	 * - Feitelijk betekent dat: gewoon alle FRST/NEW-mandaten vervangen door eentje met nieuwe ID en juiste status.
	 * @return string Status string
	 */
	private function fixAllFirstMandates() {

		// Parameters
		$updated = 0;

		// Haal de relevante mandaten op
		$mandates = CRM_Core_DAO::executeQuery("
			SELECT cvsm.*, cm.contact_id FROM civicrm_value_sepa_mandaat cvsm
			LEFT JOIN civicrm_membership_mandaat cmm ON cvsm.mandaat_nr = cmm.mandaat_id
			LEFT JOIN civicrm_membership cm ON cmm.entity_id = cm.id
			WHERE cm.status_id IN (1,2,5)
			AND cvsm.status != 'RCUR'
			AND cvsm.mandaat_nr NOT LIKE '%-3'
			");
		while($mandates->fetch()) {

			// Update mandaat-records met nieuw nummer, status FRST, verval_datum NULL
			$mandateNumberUnique = false;
			$i = 3;
			while(!$mandateNumberUnique) {
				$newMandateNr = 'MBOA-' . $mandates->contact_id . '-' . $i;
				$chk = CRM_Core_DAO::singleValueQuery("SELECT COUNT(*) FROM civicrm_value_sepa_mandaat WHERE mandaat_nr = '" . $newMandateNr . "'");
				if($chk)
					$i++;
				else
					$mandateNumberUnique = true;
			}

			CRM_Core_DAO::executeQuery("UPDATE civicrm_value_sepa_mandaat SET mandaat_nr = '" . $newMandateNr . "', status = 'FRST', verval_datum = NULL WHERE mandaat_nr = '" . $mandates->mandaat_nr . "'");
			CRM_Core_DAO::executeQuery("UPDATE civicrm_membership_mandaat SET mandaat_id = '" . $newMandateNr . "' WHERE mandaat_id = '" . $mandates->mandaat_nr . "'");
			CRM_Core_DAO::executeQuery("UPDATE civicrm_contribution_mandaat SET mandaat_id = '" . $newMandateNr . "' WHERE mandaat_id = '" . $mandates->mandaat_nr . "'");

			echo "fixAllFirstMandates: updated mandate {$mandates->mandaat_nr} to {$newMandateNr}.\n";
			$updated++;
		}

		return "fixAllFirstMandates: updated {$updated} records.\n";

		/*
		Test-queries specifiek met ROOD:
		SELECT * FROM civicrm_value_sepa_mandaat cvsm
LEFT JOIN civicrm_membership_mandaat cmm ON cvsm.mandaat_nr=cmm.mandaat_id
LEFT JOIN civicrm_membership cm ON cmm.entity_id=cm.id
WHERE cm.membership_type_id = 2
	SELECT * FROM civicrm_value_sepa_mandaat cvsm
LEFT JOIN civicrm_membership_mandaat cmm ON cvsm.mandaat_nr=cmm.mandaat_id
LEFT JOIN civicrm_membership cm ON cmm.entity_id=cm.id
WHERE cm.membership_type_id = 2 AND cvsm.status = 'NEW' AND cm.status_id IN (1,2)
	SELECT * FROM civicrm_value_sepa_mandaat cvsm
LEFT JOIN civicrm_contribution_mandaat ccm ON cvsm.mandaat_nr=ccm.mandaat_id
LEFT JOIN civicrm_contribution cc ON
ccm.entity_id = cc.id
WHERE cc.receive_date = '2015-04-01' AND  cc.financial_type_id = 7 AND cvsm.status = 'NEW' AND cvsm.mandaat_datum = '2009-11-01'
		*/
	}
}
