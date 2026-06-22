<?php

declare(strict_types=1);

namespace MailingApp;

final class PolonadsCategoryMapper
{
    private const FALLBACK_CATEGORY = [
        'id' => 4,
        'name' => 'Uslugi',
        'match_type' => 'fallback',
        'confidence' => 'low',
        'matched_keyword' => '',
        'requires_review' => true,
        'reason' => 'No confident category match. Fallback to Polonads parent category: Uslugi.',
    ];

    /**
     * @return list<array{id:int,name:string,match_type:string,confidence:string,matched_keyword:string,requires_review:bool,reason:string,keywords:list<string>}>
     */
    private const CATEGORY_RULES = [
        [
            'id' => 21,
            'name' => 'Paczki do Polski',
            'match_type' => 'keyword',
            'confidence' => 'high',
            'matched_keyword' => '',
            'requires_review' => false,
            'reason' => 'Matched parcel and Poland shipping keywords.',
            'keywords' => ['poland package', 'packages to poland', 'parcel to poland', 'shipping to poland', 'paczki do polski'],
        ],
        [
            'id' => 22,
            'name' => 'Ubezpieczenia',
            'match_type' => 'keyword',
            'confidence' => 'high',
            'matched_keyword' => '',
            'requires_review' => false,
            'reason' => 'Matched insurance keywords.',
            'keywords' => ['insurance', 'insured', 'insurer', 'coverage', 'policy', 'medicare', 'life insurance', 'health insurance'],
        ],
        [
            'id' => 14,
            'name' => 'Prawo i Finanse',
            'match_type' => 'keyword',
            'confidence' => 'high',
            'matched_keyword' => '',
            'requires_review' => false,
            'reason' => 'Matched legal, tax, accounting, or finance keywords.',
            'keywords' => ['law', 'legal', 'attorney', 'lawyer', 'tax', 'taxes', 'accounting', 'accountant', 'bookkeeping', 'finance', 'financial', 'payroll'],
        ],
        [
            'id' => 5,
            'name' => 'Budowa i Remonty',
            'match_type' => 'keyword',
            'confidence' => 'high',
            'matched_keyword' => '',
            'requires_review' => false,
            'reason' => 'Matched construction, renovation, or contractor keywords.',
            'keywords' => ['construction', 'contractor', 'roofing', 'roofer', 'remodel', 'renovation', 'renovations', 'flooring', 'drywall', 'plumber', 'plumbing', 'electric', 'electrician', 'hvac', 'heating', 'cooling', 'kitchen', 'bathroom'],
        ],
        [
            'id' => 6,
            'name' => 'Transport i Przeprowadzki',
            'match_type' => 'keyword',
            'confidence' => 'high',
            'matched_keyword' => '',
            'requires_review' => false,
            'reason' => 'Matched transport, logistics, or moving keywords.',
            'keywords' => ['moving', 'movers', 'transport', 'transportation', 'trucking', 'truck', 'delivery', 'dispatch', 'relocation', 'courier', 'freight', 'shipping'],
        ],
        [
            'id' => 9,
            'name' => 'IT i Internet',
            'match_type' => 'keyword',
            'confidence' => 'high',
            'matched_keyword' => '',
            'requires_review' => false,
            'reason' => 'Matched IT, software, or web keywords.',
            'keywords' => ['software', 'it service', 'it support', 'computer', 'web design', 'website design', 'seo', 'developer', 'development', 'app development', 'cybersecurity', 'hosting'],
        ],
        [
            'id' => 10,
            'name' => 'Reklama i Fotografia',
            'match_type' => 'keyword',
            'confidence' => 'medium',
            'matched_keyword' => '',
            'requires_review' => false,
            'reason' => 'Matched marketing, design, or photography keywords.',
            'keywords' => ['marketing', 'advertising', 'branding', 'graphic design', 'photography', 'photographer', 'videography', 'video production', 'print shop'],
        ],
        [
            'id' => 11,
            'name' => 'Opieka i Pomoc Domowa',
            'match_type' => 'keyword',
            'confidence' => 'high',
            'matched_keyword' => '',
            'requires_review' => false,
            'reason' => 'Matched care or home assistance keywords.',
            'keywords' => ['home care', 'caregiver', 'care giver', 'senior care', 'child care', 'babysitting', 'nanny', 'home health', 'assisted living', 'companion care'],
        ],
        [
            'id' => 12,
            'name' => 'Nauka i Kursy',
            'match_type' => 'keyword',
            'confidence' => 'high',
            'matched_keyword' => '',
            'requires_review' => false,
            'reason' => 'Matched education, tutoring, or training keywords.',
            'keywords' => ['school', 'tutoring', 'tutor', 'course', 'training', 'lesson', 'lessons', 'academy', 'class', 'classes', 'learning'],
        ],
        [
            'id' => 13,
            'name' => 'Zdrowie i Uroda',
            'match_type' => 'keyword',
            'confidence' => 'high',
            'matched_keyword' => '',
            'requires_review' => false,
            'reason' => 'Matched beauty, wellness, or health service keywords.',
            'keywords' => ['beauty', 'salon', 'spa', 'med spa', 'cosmetic', 'massage', 'wellness', 'medical', 'doctor', 'dentist', 'dental', 'therapy', 'therapist', 'chiropractic', 'nails', 'manicure'],
        ],
        [
            'id' => 16,
            'name' => 'Nieruchomosci',
            'match_type' => 'keyword',
            'confidence' => 'high',
            'matched_keyword' => '',
            'requires_review' => false,
            'reason' => 'Matched real estate or property keywords.',
            'keywords' => ['real estate', 'realtor', 'property', 'properties', 'mortgage', 'apartment', 'apartments', 'rental', 'rentals', 'home for sale', 'brokerage'],
        ],
        [
            'id' => 36,
            'name' => 'Organizacje Spoleczne i Religijne',
            'match_type' => 'keyword',
            'confidence' => 'medium',
            'matched_keyword' => '',
            'requires_review' => false,
            'reason' => 'Matched nonprofit, church, or community organization keywords.',
            'keywords' => ['foundation', 'nonprofit', 'charity', 'church', 'parish', 'community center', 'association', 'festival', 'cultural center'],
        ],
        [
            'id' => 23,
            'name' => 'Sprzedam/Kupie/Oddam',
            'match_type' => 'keyword',
            'confidence' => 'medium',
            'matched_keyword' => '',
            'requires_review' => false,
            'reason' => 'Matched store, retail, or product sale keywords.',
            'keywords' => ['store', 'shop', 'boutique', 'retail', 'wholesale', 'furniture', 'electronics', 'car dealer', 'auto sales', 'marketplace'],
        ],
        [
            'id' => 3,
            'name' => 'Dam prace',
            'match_type' => 'keyword',
            'confidence' => 'medium',
            'matched_keyword' => '',
            'requires_review' => false,
            'reason' => 'Matched explicit hiring or job-offer keywords.',
            'keywords' => [
                'now hiring',
                'job opening',
                'help wanted',
                'we are hiring',
                'hiring now',
                'employment opportunity',
                'career opportunity',
                'employment service',
                'employment services',
                'employment agency',
                'temporary employment',
                'staffing',
                'staffing agency',
                'job seekers',
                'employers',
                'workforce',
                'clerical',
                'industrial',
            ],
        ],
        [
            'id' => 4,
            'name' => 'Uslugi',
            'match_type' => 'keyword',
            'confidence' => 'medium',
            'matched_keyword' => '',
            'requires_review' => false,
            'reason' => 'Matched general staffing or business-service keywords.',
            'keywords' => ['recruiting', 'recruitment', 'temp agency', 'business service', 'consulting', 'agency'],
        ],
    ];

    public function map(string $sourceCategory, string $companyName = '', string $website = ''): array
    {
        $haystack = $this->normalizeText(implode(' ', [$sourceCategory, $companyName, $this->extractHost($website)]));
        return $this->mapNormalizedText($haystack);
    }

    public function mapWebsiteText(string $websiteText, string $sourceCategory = '', string $companyName = '', string $website = ''): array
    {
        $normalizedWebsiteText = $this->normalizeText($websiteText);
        if ($normalizedWebsiteText === '') {
            return $this->map($sourceCategory, $companyName, $website);
        }

        $bestRule = null;
        $bestScore = 0;
        $bestKeyword = '';

        foreach (self::CATEGORY_RULES as $rule) {
            $score = 0;
            $matchedKeyword = '';

            foreach ($rule['keywords'] as $keyword) {
                if (!$this->containsKeyword($normalizedWebsiteText, $keyword)) {
                    continue;
                }

                $score++;
                if ($matchedKeyword === '') {
                    $matchedKeyword = $keyword;
                }
            }

            if ($score > $bestScore) {
                $bestRule = $rule;
                $bestScore = $score;
                $bestKeyword = $matchedKeyword;
            }
        }

        if ($bestRule === null || $bestScore === 0) {
            return $this->map($sourceCategory, $companyName, $website);
        }

        $result = $bestRule;
        $result['match_type'] = 'website_keyword';
        $result['confidence'] = $bestScore >= 2 ? 'high' : (string) ($result['confidence'] ?? 'medium');
        $result['matched_keyword'] = $bestKeyword;
        $result['requires_review'] = false;
        $result['reason'] = sprintf(
            'Matched website content keywords for category %s. Website keyword score: %d.',
            (string) ($result['name'] ?? ''),
            $bestScore
        );
        $result['source'] = 'website_analysis';
        $result['website_keyword_score'] = $bestScore;
        unset($result['keywords']);

        return $result;
    }

    private function mapNormalizedText(string $haystack): array
    {
        foreach (self::CATEGORY_RULES as $rule) {
            foreach ($rule['keywords'] as $keyword) {
                if (!$this->containsKeyword($haystack, $keyword)) {
                    continue;
                }

                $result = $rule;
                $result['matched_keyword'] = $keyword;
                unset($result['keywords']);

                return $result;
            }
        }

        return self::FALLBACK_CATEGORY;
    }

    private function extractHost(string $website): string
    {
        $website = trim($website);
        if ($website === '') {
            return '';
        }

        $host = (string) parse_url($website, PHP_URL_HOST);
        return str_replace(['www.', '-', '.'], ' ', strtolower($host));
    }

    private function containsKeyword(string $haystack, string $keyword): bool
    {
        $needle = $this->normalizeText($keyword);
        return $needle !== '' && str_contains($haystack, $needle);
    }

    private function normalizeText(string $value): string
    {
        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? '';
        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    }
}
