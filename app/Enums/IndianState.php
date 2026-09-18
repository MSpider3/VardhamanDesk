<?php

namespace App\Enums;

enum IndianState: string
{
    case JAMMU_AND_KASHMIR = '01';
    case HIMACHAL_PRADESH = '02';
    case PUNJAB = '03';
    case CHANDIGARH = '04';
    case UTTARAKHAND = '05';
    case HARYANA = '06';
    case DELHI = '07';
    case RAJASTHAN = '08';
    case UTTAR_PRADESH = '09';
    case BIHAR = '10';
    case SIKKIM = '11';
    case ARUNACHAL_PRADESH = '12';
    case NAGALAND = '13';
    case MANIPUR = '14';
    case MIZORAM = '15';
    case TRIPURA = '16';
    case MEGHALAYA = '17';
    case ASSAM = '18';
    case WEST_BENGAL = '19';
    case JHARKHAND = '20';
    case ODISHA = '21';
    case CHHATTISGARH = '22';
    case MADHYA_PRADESH = '23';
    case GUJARAT = '24';
    case DADRA_AND_NAGAR_HAVELI_AND_DAMAN_AND_DIU = '26';
    case MAHARASHTRA = '27';
    case ANDHRA_PRADESH_OLD = '28';
    case KARNATAKA = '29';
    case GOA = '30';
    case LAKSHADWEEP = '31';
    case KERALA = '32';
    case TAMIL_NADU = '33';
    case PUDUCHERRY = '34';
    case ANDAMAN_AND_NICOBAR_ISLANDS = '35';
    case TELANGANA = '36';
    case ANDHRA_PRADESH = '37';
    case LADAKH = '38';
    case OTHER_TERRITORY = '97';

    public function code(): string
    {
        return $this->value;
    }

    public function label(): string
    {
        return match ($this) {
            self::JAMMU_AND_KASHMIR => 'Jammu and Kashmir (01)',
            self::HIMACHAL_PRADESH => 'Himachal Pradesh (02)',
            self::PUNJAB => 'Punjab (03)',
            self::CHANDIGARH => 'Chandigarh (04)',
            self::UTTARAKHAND => 'Uttarakhand (05)',
            self::HARYANA => 'Haryana (06)',
            self::DELHI => 'Delhi (07)',
            self::RAJASTHAN => 'Rajasthan (08)',
            self::UTTAR_PRADESH => 'Uttar Pradesh (09)',
            self::BIHAR => 'Bihar (10)',
            self::SIKKIM => 'Sikkim (11)',
            self::ARUNACHAL_PRADESH => 'Arunachal Pradesh (12)',
            self::NAGALAND => 'Nagaland (13)',
            self::MANIPUR => 'Manipur (14)',
            self::MIZORAM => 'Mizoram (15)',
            self::TRIPURA => 'Tripura (16)',
            self::MEGHALAYA => 'Meghalaya (17)',
            self::ASSAM => 'Assam (18)',
            self::WEST_BENGAL => 'West Bengal (19)',
            self::JHARKHAND => 'Jharkhand (20)',
            self::ODISHA => 'Odisha (21)',
            self::CHHATTISGARH => 'Chhattisgarh (22)',
            self::MADHYA_PRADESH => 'Madhya Pradesh (23)',
            self::GUJARAT => 'Gujarat (24)',
            self::DADRA_AND_NAGAR_HAVELI_AND_DAMAN_AND_DIU => 'Dadra and Nagar Haveli and Daman and Diu (26)',
            self::MAHARASHTRA => 'Maharashtra (27)',
            self::ANDHRA_PRADESH_OLD => 'Andhra Pradesh (Old) (28)',
            self::KARNATAKA => 'Karnataka (29)',
            self::GOA => 'Goa (30)',
            self::LAKSHADWEEP => 'Lakshadweep (31)',
            self::KERALA => 'Kerala (32)',
            self::TAMIL_NADU => 'Tamil Nadu (33)',
            self::PUDUCHERRY => 'Puducherry (34)',
            self::ANDAMAN_AND_NICOBAR_ISLANDS => 'Andaman and Nicobar Islands (35)',
            self::TELANGANA => 'Telangana (36)',
            self::ANDHRA_PRADESH => 'Andhra Pradesh (37)',
            self::LADAKH => 'Ladakh (38)',
            self::OTHER_TERRITORY => 'Other Territory (97)',
        };
    }

    public function stateName(): string
    {
        return match ($this) {
            self::JAMMU_AND_KASHMIR => 'Jammu and Kashmir',
            self::HIMACHAL_PRADESH => 'Himachal Pradesh',
            self::PUNJAB => 'Punjab',
            self::CHANDIGARH => 'Chandigarh',
            self::UTTARAKHAND => 'Uttarakhand',
            self::HARYANA => 'Haryana',
            self::DELHI => 'Delhi',
            self::RAJASTHAN => 'Rajasthan',
            self::UTTAR_PRADESH => 'Uttar Pradesh',
            self::BIHAR => 'Bihar',
            self::SIKKIM => 'Sikkim',
            self::ARUNACHAL_PRADESH => 'Arunachal Pradesh',
            self::NAGALAND => 'Nagaland',
            self::MANIPUR => 'Manipur',
            self::MIZORAM => 'Mizoram',
            self::TRIPURA => 'Tripura',
            self::MEGHALAYA => 'Meghalaya',
            self::ASSAM => 'Assam',
            self::WEST_BENGAL => 'West Bengal',
            self::JHARKHAND => 'Jharkhand',
            self::ODISHA => 'Odisha',
            self::CHHATTISGARH => 'Chhattisgarh',
            self::MADHYA_PRADESH => 'Madhya Pradesh',
            self::GUJARAT => 'Gujarat',
            self::DADRA_AND_NAGAR_HAVELI_AND_DAMAN_AND_DIU => 'Dadra and Nagar Haveli and Daman and Diu',
            self::MAHARASHTRA => 'Maharashtra',
            self::ANDHRA_PRADESH_OLD => 'Andhra Pradesh (Old)',
            self::KARNATAKA => 'Karnataka',
            self::GOA => 'Goa',
            self::LAKSHADWEEP => 'Lakshadweep',
            self::KERALA => 'Kerala',
            self::TAMIL_NADU => 'Tamil Nadu',
            self::PUDUCHERRY => 'Puducherry',
            self::ANDAMAN_AND_NICOBAR_ISLANDS => 'Andaman and Nicobar Islands',
            self::TELANGANA => 'Telangana',
            self::ANDHRA_PRADESH => 'Andhra Pradesh',
            self::LADAKH => 'Ladakh',
            self::OTHER_TERRITORY => 'Other Territory',
        };
    }

    /**
     * Resolve enum from either 2-digit code or exact state name.
     */
    public static function fromCodeOrName(string|self $value): ?self
    {
        if ($value instanceof self) {
            return $value;
        }

        $codeMatch = self::tryFrom($value);
        if ($codeMatch) {
            return $codeMatch;
        }

        $trimmed = trim(strtolower($value));
        foreach (self::cases() as $case) {
            if (strtolower($case->stateName()) === $trimmed || strtolower($case->name) === $trimmed) {
                return $case;
            }
        }

        return null;
    }
}
