<?php

require_once($_SERVER['DOCUMENT_ROOT'].'/classes/DSS/DssPrint.php');
require_once($_SERVER['DOCUMENT_ROOT'].'/classes/DSS/DssPDO.php');

$DssParameter       = new DssParameter();
$DssPDO             = new DssPDO('genesisdb', false);
$DssDocManager      = new DssDocManager();
$DssPrint           = new DssPrint();
$DssNaechsteNummmer = new DssNaechsteNummer();

switch($this->mask['act']) {
    case 'get':
        break;
    case 'save':
        break;
    case 'ajax':
        switch($_REQUEST['act']) {
            case 'showPreview':

                // Sammelt benötigte Daten für jede selektierte Rückmeldung
                $data           = $_REQUEST['checked'];
                $projId         = $data[0]['projektid'];
                $mitarbeiter    = $data[0]['mitarbeiter'];
                $aufwand        = array();
                $checkPreview   = true;

                foreach($data as $checkedRow) {

                    $projektId                  = $checkedRow['projektid'];
                    $aufgId                     = $checkedRow['aufgabenid'];
                    $rueckId                    = $checkedRow['rueckmeldungid'];
                    $printData['headData'][]    = ["PRU1_PROJEKT_ID" => $projektId, "PRU1_AUFGABEN_ID" => $aufgId, "PRU1_RUECKMELDUNG_ID" => $rueckId];

                    // Sammeln des fakturierbaren Aufwands
                    if($checkedRow['faktura'] == "Y")
                        $aufwand[]     = $checkedRow['zeitaufwand'];

                    // Prüfen ob eine Fahrt für die Rückmeldung existiert
                    if($checkedRow['anreise'] > 0 && $checkedRow['faktura'] == "Y")
                        $printData['Fahrten'][] = $checkedRow['anreise'];

                    // Prüfen ob Rückmeldung bereit für Abrechnung oder bereits abgerechnet ist
                    if($checkedRow['status'] == "A")
                        $checkPreview = false;

                }

                $printData['Projekt']['ID']             = $projId;
                $printData['Projekt']['Mitarbeiter']    = $mitarbeiter;
                $printData['Summe']                     = array_sum($aufwand);
                $printData['Formular']                  = $DssParameter->leseParameter('FORMULARE')['DruckStandardText']['Leistungsschein'];
                $printData['Kunde']                     = $this->DssDB_program->execute("
                    SELECT
                        PPR1_KUNDEN_ID                    
                    FROM
                        PMPR01                    
                    WHERE
                        {$this->DssDB_program->checkFma('pmpr01', true, true)}
                        AND PPR1_PROJEKT_ID         = :proj
                    ", array(
                    ':proj' => $projId
                ), 2);

                if($checkPreview == true) {
                    $DssPrint->createTempFile($printData);
                    echo json_encode($printData);
                } else {
                    echo json_encode(["preview" => 0]);
                    break;
                }

                break;

            case 'saveTimeFeedback':

                // Sammelt benötigte Daten für jede selektierte Rückmeldung
                $data           = $_REQUEST['checkedValues'];
                $projId         = $data[0]['projektid'];
                $kundenNr       = $data[0]['kundennummer'];
                $projektLeiter  = $data[0]['mitarbeiter'];
                $aufwand        = array();
                $checkStatus    = true;

                foreach($data as $checkedRow) {

                    $projektId                  = $checkedRow['projektid'];
                    $aufgId                     = $checkedRow['aufgabenid'];
                    $rueckId                    = $checkedRow['rueckmeldungid'];
                    $printData['headData'][]    = ["PRU1_PROJEKT_ID" => $projektId, "PRU1_AUFGABEN_ID" => $aufgId, "PRU1_RUECKMELDUNG_ID" => $rueckId];

                    // Sammeln des fakturierbaren Aufwands
                    if($checkedRow['faktura'] == "Y")
                        $aufwand[]     = $checkedRow['zeitaufwand'];

                    // Prüfen ob eine Fahrt für die Rückmeldung existiert
                    if($checkedRow['anreise'] > 0 && $checkedRow['faktura'] == "Y")
                        $printData['Fahrten'][] = $checkedRow['anreise'];

                    // Prüfen ob Rückmeldung bereit für Abrechnung oder bereits abgerechnet ist
                    if($checkedRow['status'] == "A" || $checkedRow['status'] == "O")
                        $checkStatus = false;

                }

                $printData['Projekt']['ID']             = $projId;
                $printData['Projekt']['Mitarbeiter']    = $projektLeiter;
                $printData['Summe']                     = array_sum($aufwand);
                $printData['Kunde']                     = $this->DssDB_program->execute("
                    SELECT
                        PPR1_KUNDEN_ID                    
                    FROM
                        PMPR01                    
                    WHERE
                        {$this->DssDB_program->checkFma('pmpr01', true, true)}
                        AND PPR1_PROJEKT_ID         = :proj
                    ", array(
                    ':proj' => $projId
                ), 2);

                // Leistungsschein erstellen
                $check = false;

                if($checkStatus == true) {
                    try {
                        $date       = date('Ymd');
                        $template   = $DssParameter->leseParameter('FORMULARE')['DruckStandardText']['Leistungsschein'];
                        $filename   = $DssPrint->savePDF($template, $printData, '13', $projId, array('directSave' => true, 'filePrefix' => 'LN'));
                        $number     = $DssNaechsteNummmer->getNextId('LEIS');

                        $optionsdata = array(
                            'doctype'   => '13',
                            'dockey'    => $projId,
                            'filename'  => "LN_".$number."_".$date.".pdf",
                            'content'   => file_get_contents($filename)
                        );

                        $return     = $DssDocManager->checkInFile($optionsdata);
                        $check      = true;
                    } catch(Exception $e) {
                        $check = false;
                        $e->getMessage();
                    }
                } else {
                    echo json_encode(["preview" => 0]);
                    break;
                }


                // Wenn Leistungsschein erstellt wurde, setze Status auf "Abgerechnet"
                foreach($data as $checkedRow) {

                    $projekt        = $checkedRow['projektid'];
                    $aufgabe        = $checkedRow['aufgabenid'];
                    $rueckmeldung   = $checkedRow['rueckmeldungid'];

                    if($check == true) {
                        $status     = "A";

                        $data = array(
                            'PRU1_PROJEKT_ID'       => $projekt,
                            'PRU1_AUFGABEN_ID'      => $aufgabe,
                            'PRU1_RUECKMELDUNG_ID'  => $rueckmeldung,
                            'PRU1_STATUS'           => $status
                        );

                        $sql = $DssPDO->createSaveStatement('PMRU01',  $data);
                        $DssPDO->execute($sql, array());
                    }
                }

                echo json_encode($printData);
                break;

        }
        break;
}
