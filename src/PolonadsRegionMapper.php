<?php

declare(strict_types=1);

namespace MailingApp;

final class PolonadsRegionMapper
{
    private const COUNTRY_USA = [
        'id' => 1,
        'name' => 'USA',
        'level' => 'country',
        'parent_id' => 0,
        'match_type' => 'fallback',
        'confidence' => 'low',
        'matched_city' => '',
        'matched_state' => '',
        'requires_review' => true,
        'reason' => 'No confident city or state match. Fallback to USA.',
    ];

    /**
     * @var array<string, array{id:int,name:string}>
     */
    private const STATES = [
        'AL' => ['id' => 51, 'name' => 'Alabama'],
        'AK' => ['id' => 53, 'name' => 'Alaska'],
        'AZ' => ['id' => 25, 'name' => 'Arizona'],
        'AR' => ['id' => 55, 'name' => 'Arkansas'],
        'CA' => ['id' => 22, 'name' => 'California'],
        'CO' => ['id' => 57, 'name' => 'Colorado'],
        'CT' => ['id' => 18, 'name' => 'Connecticut'],
        'DE' => ['id' => 61, 'name' => 'Delaware'],
        'GA' => ['id' => 63, 'name' => 'Georgia'],
        'HI' => ['id' => 65, 'name' => 'Hawaii'],
        'ID' => ['id' => 67, 'name' => 'Idaho'],
        'IL' => ['id' => 2, 'name' => 'Illinois'],
        'IN' => ['id' => 69, 'name' => 'Indiana'],
        'IA' => ['id' => 71, 'name' => 'Iowa'],
        'KS' => ['id' => 73, 'name' => 'Kansas'],
        'KY' => ['id' => 75, 'name' => 'Kentucky'],
        'LA' => ['id' => 77, 'name' => 'Louisiana'],
        'ME' => ['id' => 79, 'name' => 'Maine'],
        'MI' => ['id' => 13, 'name' => 'Michigan'],
        'MN' => ['id' => 81, 'name' => 'Minnesota'],
        'MS' => ['id' => 83, 'name' => 'Mississippi'],
        'MO' => ['id' => 85, 'name' => 'Missouri'],
        'MT' => ['id' => 87, 'name' => 'Montana'],
        'NE' => ['id' => 89, 'name' => 'Nebraska'],
        'NV' => ['id' => 91, 'name' => 'Nevada'],
        'NH' => ['id' => 93, 'name' => 'NewHampshire'],
        'NJ' => ['id' => 95, 'name' => 'NewJersey'],
        'NM' => ['id' => 97, 'name' => 'NewMexico'],
        'NY' => ['id' => 4, 'name' => 'NewYork'],
        'NC' => ['id' => 99, 'name' => 'NorthCarolina'],
        'ND' => ['id' => 101, 'name' => 'NorthDakota'],
        'OH' => ['id' => 10, 'name' => 'Ohio'],
        'OK' => ['id' => 103, 'name' => 'Oklahoma'],
        'OR' => ['id' => 105, 'name' => 'Oregon'],
        'PA' => ['id' => 8, 'name' => 'Pennsylvania'],
        'RI' => ['id' => 107, 'name' => 'RhodeIsland'],
        'SC' => ['id' => 109, 'name' => 'SouthCarolina'],
        'SD' => ['id' => 111, 'name' => 'SouthDakota'],
        'TN' => ['id' => 113, 'name' => 'Tennessee'],
        'TX' => ['id' => 115, 'name' => 'Texas'],
        'UT' => ['id' => 117, 'name' => 'Utah'],
        'VT' => ['id' => 119, 'name' => 'Vermont'],
        'VA' => ['id' => 121, 'name' => 'Virginia'],
        'WA' => ['id' => 123, 'name' => 'Washington'],
        'WV' => ['id' => 125, 'name' => 'WestVirginia'],
        'WI' => ['id' => 20, 'name' => 'Wisconsin'],
        'WY' => ['id' => 129, 'name' => 'Wyoming'],
    ];

    /**
     * @var array<string, array{id:int,name:string,parent_id:int,state_abbr:string}>
     */
    private const CITIES = [
        'chicago|IL' => ['id' => 3, 'name' => 'Chicago', 'parent_id' => 2, 'state_abbr' => 'IL'],
        'buffalo|NY' => ['id' => 6, 'name' => 'Buffalo', 'parent_id' => 4, 'state_abbr' => 'NY'],
        'cheektowaga|NY' => ['id' => 7, 'name' => 'Cheektowaga', 'parent_id' => 4, 'state_abbr' => 'NY'],
        'new york city|NY' => ['id' => 5, 'name' => 'NewYorkCity', 'parent_id' => 4, 'state_abbr' => 'NY'],
        'new york|NY' => ['id' => 5, 'name' => 'NewYorkCity', 'parent_id' => 4, 'state_abbr' => 'NY'],
        'nyc|NY' => ['id' => 5, 'name' => 'NewYorkCity', 'parent_id' => 4, 'state_abbr' => 'NY'],
        'philadelphia|PA' => ['id' => 9, 'name' => 'Philadelphia', 'parent_id' => 8, 'state_abbr' => 'PA'],
        'cleveland|OH' => ['id' => 11, 'name' => 'Cleveland', 'parent_id' => 10, 'state_abbr' => 'OH'],
        'toledo|OH' => ['id' => 12, 'name' => 'Toledo', 'parent_id' => 10, 'state_abbr' => 'OH'],
        'detroit|MI' => ['id' => 14, 'name' => 'Detroit', 'parent_id' => 13, 'state_abbr' => 'MI'],
        'warren|MI' => ['id' => 15, 'name' => 'Warren', 'parent_id' => 13, 'state_abbr' => 'MI'],
        'sterling heights|MI' => ['id' => 16, 'name' => 'SterlingHeights', 'parent_id' => 13, 'state_abbr' => 'MI'],
        'grand rapids|MI' => ['id' => 17, 'name' => 'GrandRapids', 'parent_id' => 13, 'state_abbr' => 'MI'],
        'new britain|CT' => ['id' => 19, 'name' => 'NewBritain', 'parent_id' => 18, 'state_abbr' => 'CT'],
        'milwaukee|WI' => ['id' => 21, 'name' => 'Milwaukee', 'parent_id' => 20, 'state_abbr' => 'WI'],
        'los angeles|CA' => ['id' => 23, 'name' => 'LosAngeles', 'parent_id' => 22, 'state_abbr' => 'CA'],
        'san diego|CA' => ['id' => 24, 'name' => 'SanDiego', 'parent_id' => 22, 'state_abbr' => 'CA'],
        'phoenix|AZ' => ['id' => 26, 'name' => 'Phoenix', 'parent_id' => 25, 'state_abbr' => 'AZ'],
        'huntsville|AL' => ['id' => 52, 'name' => 'Huntsville', 'parent_id' => 51, 'state_abbr' => 'AL'],
        'anchorage|AK' => ['id' => 54, 'name' => 'Anchorage', 'parent_id' => 53, 'state_abbr' => 'AK'],
        'little rock|AR' => ['id' => 56, 'name' => 'LittleRock', 'parent_id' => 55, 'state_abbr' => 'AR'],
        'denver|CO' => ['id' => 58, 'name' => 'Denver', 'parent_id' => 57, 'state_abbr' => 'CO'],
        'bridgeport|CT' => ['id' => 60, 'name' => 'Bridgeport', 'parent_id' => 59, 'state_abbr' => 'CT'],
        'wilmington|DE' => ['id' => 62, 'name' => 'Wilmington', 'parent_id' => 61, 'state_abbr' => 'DE'],
        'atlanta|GA' => ['id' => 64, 'name' => 'Atlanta', 'parent_id' => 63, 'state_abbr' => 'GA'],
        'honolulu|HI' => ['id' => 66, 'name' => 'Honolulu', 'parent_id' => 65, 'state_abbr' => 'HI'],
        'boise|ID' => ['id' => 68, 'name' => 'Boise', 'parent_id' => 67, 'state_abbr' => 'ID'],
        'indianapolis|IN' => ['id' => 70, 'name' => 'Indianapolis', 'parent_id' => 69, 'state_abbr' => 'IN'],
        'des moines|IA' => ['id' => 72, 'name' => 'DesMoines', 'parent_id' => 71, 'state_abbr' => 'IA'],
        'wichita|KS' => ['id' => 74, 'name' => 'Wichita', 'parent_id' => 73, 'state_abbr' => 'KS'],
        'louisville|KY' => ['id' => 76, 'name' => 'Louisville', 'parent_id' => 75, 'state_abbr' => 'KY'],
        'new orleans|LA' => ['id' => 78, 'name' => 'NewOrleans', 'parent_id' => 77, 'state_abbr' => 'LA'],
        'portland|ME' => ['id' => 80, 'name' => 'Portland', 'parent_id' => 79, 'state_abbr' => 'ME'],
        'minneapolis|MN' => ['id' => 82, 'name' => 'Minneapolis', 'parent_id' => 81, 'state_abbr' => 'MN'],
        'jackson|MS' => ['id' => 84, 'name' => 'Jackson', 'parent_id' => 83, 'state_abbr' => 'MS'],
        'kansas city|MO' => ['id' => 86, 'name' => 'KansasCity', 'parent_id' => 85, 'state_abbr' => 'MO'],
        'billings|MT' => ['id' => 88, 'name' => 'Billings', 'parent_id' => 87, 'state_abbr' => 'MT'],
        'omaha|NE' => ['id' => 90, 'name' => 'Omaha', 'parent_id' => 89, 'state_abbr' => 'NE'],
        'las vegas|NV' => ['id' => 92, 'name' => 'LasVegas', 'parent_id' => 91, 'state_abbr' => 'NV'],
        'manchester|NH' => ['id' => 94, 'name' => 'Manchester', 'parent_id' => 93, 'state_abbr' => 'NH'],
        'newark|NJ' => ['id' => 96, 'name' => 'Newark', 'parent_id' => 95, 'state_abbr' => 'NJ'],
        'albuquerque|NM' => ['id' => 98, 'name' => 'Albuquerque', 'parent_id' => 97, 'state_abbr' => 'NM'],
        'charlotte|NC' => ['id' => 100, 'name' => 'Charlotte', 'parent_id' => 99, 'state_abbr' => 'NC'],
        'fargo|ND' => ['id' => 102, 'name' => 'Fargo', 'parent_id' => 101, 'state_abbr' => 'ND'],
        'oklahoma city|OK' => ['id' => 104, 'name' => 'OklahomaCity', 'parent_id' => 103, 'state_abbr' => 'OK'],
        'portland|OR' => ['id' => 106, 'name' => 'Portland', 'parent_id' => 105, 'state_abbr' => 'OR'],
        'providence|RI' => ['id' => 108, 'name' => 'Providence', 'parent_id' => 107, 'state_abbr' => 'RI'],
        'charleston|SC' => ['id' => 110, 'name' => 'Charleston', 'parent_id' => 109, 'state_abbr' => 'SC'],
        'sioux falls|SD' => ['id' => 112, 'name' => 'SiouxFalls', 'parent_id' => 111, 'state_abbr' => 'SD'],
        'nashville|TN' => ['id' => 114, 'name' => 'Nashville', 'parent_id' => 113, 'state_abbr' => 'TN'],
        'houston|TX' => ['id' => 116, 'name' => 'Houston', 'parent_id' => 115, 'state_abbr' => 'TX'],
        'salt lake city|UT' => ['id' => 118, 'name' => 'SaltLakeCity', 'parent_id' => 117, 'state_abbr' => 'UT'],
        'burlington|VT' => ['id' => 120, 'name' => 'Burlington', 'parent_id' => 119, 'state_abbr' => 'VT'],
        'virginia beach|VA' => ['id' => 122, 'name' => 'VirginiaBeach', 'parent_id' => 121, 'state_abbr' => 'VA'],
        'seattle|WA' => ['id' => 124, 'name' => 'Seattle', 'parent_id' => 123, 'state_abbr' => 'WA'],
        'charleston|WV' => ['id' => 126, 'name' => 'Charleston', 'parent_id' => 125, 'state_abbr' => 'WV'],
        'cheyenne|WY' => ['id' => 130, 'name' => 'Cheyenne', 'parent_id' => 129, 'state_abbr' => 'WY'],
    ];

    public function map(string $city, string $state): array
    {
        $normalizedState = $this->normalizeState($state);
        $normalizedCity = $this->normalizeText($city);

        if ($normalizedCity !== '' && $normalizedState !== '') {
            $cityKey = $normalizedCity . '|' . $normalizedState;
            if (isset(self::CITIES[$cityKey])) {
                $match = self::CITIES[$cityKey];

                return [
                    'id' => $match['id'],
                    'name' => $match['name'],
                    'level' => 'city',
                    'parent_id' => $match['parent_id'],
                    'match_type' => 'city_state_exact',
                    'confidence' => 'high',
                    'matched_city' => $city,
                    'matched_state' => $normalizedState,
                    'requires_review' => false,
                    'reason' => 'Matched Polonads city by exact city and state.',
                ];
            }
        }

        if ($normalizedState !== '' && isset(self::STATES[$normalizedState])) {
            $stateMatch = self::STATES[$normalizedState];

            return [
                'id' => $stateMatch['id'],
                'name' => $stateMatch['name'],
                'level' => 'state',
                'parent_id' => 1,
                'match_type' => 'state_fallback',
                'confidence' => 'medium',
                'matched_city' => $city,
                'matched_state' => $normalizedState,
                'requires_review' => false,
                'reason' => 'City not matched. Fallback to Polonads state.',
            ];
        }

        $fallback = self::COUNTRY_USA;
        $fallback['matched_city'] = $city;
        $fallback['matched_state'] = $normalizedState;

        return $fallback;
    }

    private function normalizeState(string $state): string
    {
        $state = strtoupper(trim($state));
        if ($state === '') {
            return '';
        }

        if (isset(self::STATES[$state])) {
            return $state;
        }

        $normalized = $this->normalizeText($state);
        foreach (self::STATES as $abbr => $definition) {
            if ($this->normalizeText($definition['name']) === $normalized) {
                return $abbr;
            }
        }

        return '';
    }

    private function normalizeText(string $value): string
    {
        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? '';
        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    }
}
