<?

class RoomTemperatureController extends IPSModule
{
    // Constructor
    public function Create()
    {
        parent::Create();

        // Properties for the form
        $this->RegisterPropertyString("Thermometer", "");
        $this->RegisterPropertyString("HeatingValves", "");


        // Instanzvariablen
        $this->RegisterVariableFloat("CurrentTargetTemp", "Akt. Soll-Temperatur", "~Temperature.Room", 0);
        $this->RegisterVariableFloat("CurrentTemp", "Akt. Temperatur", "~Temperature.Room", 1);
        $this->RegisterVariableBoolean("HeatingRequirement", "Heizbedarf", "", 2);

        $this->CreateWeekPlan();
        $this->RegisterVariableFloat("TargetTempBlue", "Soll-Temperatur Blau", "~Temperature.Room", 21);
        $this->RegisterVariableFloat("TargetTempGreen", "Soll-Temperatur Grün", "~Temperature.Room", 22);
        $this->RegisterVariableFloat("TargetTempRed", "Soll-Temperatur Rot", "~Temperature.Room", 23);

        $targetTempDifferenceId = $this->RegisterVariableFloat("TargetTempDifference", "Soll-Temp. Abweichung", "~Temperature", 3);
        $valveProtectionModeId = $this->RegisterVariableInteger("ValveProtectionMode", "ValveProtectionMode", "", 4);


        // Enable editing in web
        $this->EnableAction("CurrentTargetTemp");
        $this->EnableAction("TargetTempBlue");
        $this->EnableAction("TargetTempGreen");
        $this->EnableAction("TargetTempRed");


        // Timer
        $this->RegisterTimer("HeatingStatusTimer", 0, "RTC_ComputeHeatingStatus(".$this->InstanceID.");");
        $this->RegisterTimer("ValveProtectionTimer", 0, "RTC_ValveProtection(".$this->InstanceID.");");

        // Set default values
        SetValueInteger($valveProtectionModeId, -1);

        // Set variables hidden
        IPS_SetHidden($valveProtectionModeId, true);
        IPS_SetHidden($targetTempDifferenceId, true);
    }

    // Will be called after saving the form
    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->RegisterMessage($this->GetIDForIdent("HeatingRequirement"), 10603 /*VM_UPDATE*/);
        $this->RegisterMessage($this->GetIDForIdent("CurrentTargetTemp"), 10603 /*VM_UPDATE*/);


        // Set interval to 5 minutes
        $this->SetTimerInterval("HeatingStatusTimer", 1000 * 60 * 5);

        // Set default values

        $this->CreateValveProtectionEvent();
        $this->SetDefaultTargetTemperature();

        $this->ComputeHeatingStatus();
    }

    private function SetDefaultTargetTemperature()
    {
        $targetTempId = $this->GetIDForIdent("CurrentTargetTemp");
        $targetTemp = GetValueFloat($targetTempId);

        if ($targetTemp === 0)
            SetValueFloat($this->GetIDForIdent("CurrentTargetTemp"), -1);
    }

    private function CreateWeekPlan()
    {
        //search for already available event with proper ident
        $eventId = @IPS_GetObjectIDByIdent("Schedule", $this->InstanceID);
        if ($eventId === false)
        {
            $eventId = IPS_CreateEvent(2);

            IPS_SetEventActive($eventId, false);

            //configure it
            IPS_SetParent($eventId, $this->InstanceID);
            IPS_SetIdent($eventId, "Schedule");
            IPS_SetName($eventId, "Heizzeiten");
            IPS_SetPosition($eventId, 20);
            IPS_SetEventScheduleGroup($eventId, 2, 1);
            IPS_SetEventScheduleGroup($eventId, 3, 2);
            IPS_SetEventScheduleGroup($eventId, 4, 4);
            IPS_SetEventScheduleGroup($eventId, 5, 8);
            IPS_SetEventScheduleGroup($eventId, 6, 16);
            IPS_SetEventScheduleGroup($eventId, 7, 32);
            IPS_SetEventScheduleGroup($eventId, 8, 64);
            IPS_SetEventScheduleAction($eventId, 0, "Soll-Temp. Rot", 0xFF0000, "RTC_SetRedTargetTemperature(".$this->InstanceID.");");
            IPS_SetEventScheduleAction($eventId, 1, "Soll-Temp. Grün", 0x00FF00, "RTC_SetGreenTargetTemperature(".$this->InstanceID.");");
            IPS_SetEventScheduleAction($eventId, 2, "Soll-Temp. Blau", 0x0000FF, "RTC_SetBlueTargetTemperature(".$this->InstanceID.");");
            IPS_SetEventScheduleGroupPoint($eventId, 2, 0, 0, 0, 0, 2);
            IPS_SetEventScheduleGroupPoint($eventId, 3, 0, 0, 0, 0, 2);
            IPS_SetEventScheduleGroupPoint($eventId, 4, 0, 0, 0, 0, 2);
            IPS_SetEventScheduleGroupPoint($eventId, 5, 0, 0, 0, 0, 2);
            IPS_SetEventScheduleGroupPoint($eventId, 6, 0, 0, 0, 0, 2);
            IPS_SetEventScheduleGroupPoint($eventId, 7, 0, 0, 0, 0, 2);
            IPS_SetEventScheduleGroupPoint($eventId, 8, 0, 0, 0, 0, 2);
        }
    }

    private function CreateValveProtectionEvent()
    {
        //search for already available event with proper ident
        $eventId = @IPS_GetObjectIDByIdent("ValveClampProtectionEvent", $this->InstanceID);
        if ($eventId === false)
        {
            $eventId = IPS_CreateEvent(1);

            IPS_SetEventActive($eventId, true);

            //configure it
            IPS_SetParent($eventId, $this->InstanceID);
            IPS_SetIdent($eventId, "ValveClampProtectionEvent");
            IPS_SetName($eventId, "Ventil Klemmschutz");

            IPS_SetEventCyclic($eventId, 3, 1, 64, 0, 0, 0);
            IPS_SetEventScript($eventId, "RTC_ValveProtection(" . $this->InstanceID . ");");
            IPS_SetHidden($eventId, true);
        }
    }

    public function ValveProtection()
    {
        $valveProtectionModeId = $this->GetIDForIdent("ValveProtectionMode");

        $valveProtectionMode = GetValueInteger($valveProtectionModeId);
        if ($valveProtectionMode == -1)
        {
            SetValueInteger($valveProtectionModeId, 1);
            $timerInterval = 5 * 60 * 1000;

            $this->SetValves(true, true);
        }
        else if ($valveProtectionMode == 1)
        {
            SetValueInteger($valveProtectionModeId, 2);
            $timerInterval = 5 * 60 * 1000;

            $this->SetValves(false, true);
        }
        else
        {
            SetValueInteger($valveProtectionModeId, -1);
            $timerInterval = 0;

            $this->ComputeHeatingStatus();
        }

        $this->SetTimerInterval("ValveProtectionTimer", $timerInterval);
    }

    // The central EventHandler
    public function MessageSink($TimeStamp, $SenderId, $Message, $Data)
    {
        $newValue = $Data[0];
        $oldValue = $Data[2];
        $changed = $newValue != $oldValue;

        if ($SenderId == $this->GetIDForIdent("HeatingRequirement"))
        {
            $this->SetValves($newValue == 1, false);
        }
        else if ($SenderId == $this->GetIDForIdent("CurrentTargetTemp"))
        {
            $this->ComputeHeatingStatus();
        }
    }


    public function RequestAction($ident, $value)
    {
        SetValue($this->GetIDForIdent($ident), $value);
    }

    public function ComputeHeatingStatus()
    {
        //$this->SendDebug("ComputeHeatingStatus", "$this->InstanceID", 0);

        $targetTempId = $this->GetIDForIdent("CurrentTargetTemp");

        $currentTemp = $this->GetCurrentTemperature();
        $targetTemp = GetValueFloat($targetTempId);
        $heatStatus = $currentTemp < $targetTemp;

        SetValueBoolean($this->GetIDForIdent("HeatingRequirement"), $heatStatus);
        SetValueFloat($this->GetIDForIdent("CurrentTemp"), $currentTemp);
        SetValueFloat($this->GetIDForIdent("TargetTempDifference"), $currentTemp - $targetTemp);
    }

    private function GetCurrentTemperature()
    {
        $temp = 0;
        $thermometers = json_decode($this->ReadPropertyString("Thermometer"));
        if ($thermometers != null)
        {
            foreach ($thermometers as $thermometer)
            {
                $temp += GetValueFloat($thermometer->ID);
            }

            return $temp / count($thermometers);
        }

        return -2;
    }

    private function SetValves($powerStatus, $ignoreValveProtection)
    {
        $valveProtection = GetValueInteger($this->GetIDForIdent("ValveProtectionMode"));
        if ($valveProtection != -1 && !$ignoreValveProtection)
            return;


        $valves = json_decode($this->ReadPropertyString("HeatingValves"));
        if ($valves != null)
        {
            foreach ($valves as $valve)
            {
                EIB_Switch($valve->ID, $powerStatus);
            }
        }
    }

    public function SetRedTargetTemperature()
    {
        $targetTemp = GetValueFloat($this->GetIDForIdent("TargetTempRed"));
        $this->SetTargetTemperature($targetTemp);
    }

    public function SetGreenTargetTemperature()
    {
        $targetTemp = GetValueFloat($this->GetIDForIdent("TargetTempGreen"));
        $this->SetTargetTemperature($targetTemp);
    }

    public function SetBlueTargetTemperature()
    {
        $targetTemp = GetValueFloat($this->GetIDForIdent("TargetTempBlue"));
        $this->SetTargetTemperature($targetTemp);
    }

    private function SetTargetTemperature($newTargetTemperature)
    {
        SetValueFloat($this->GetIDForIdent("CurrentTargetTemp"), $newTargetTemperature);
    }
}

?>