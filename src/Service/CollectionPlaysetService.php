<?php

namespace App\Service;

use App\Client\AlteredCoreClient;
use App\Entity\User;
use App\Repository\CollectionCardViewRepository;

/**
 * Builds the per-(faction × cardSet × quantity-bucket) breakdown of a user's collection.
 *
 * Buckets:
 *   "0"  → cards the user does NOT own (the set's universe minus the owned references),
 *   "1"  → owned with quantity exactly 1,
 *   "2"  → owned with quantity exactly 2,
 *   "3+" → owned with quantity 3 or more.
 *
 * The grid is always emitted in full: every faction × set combination appears, even when
 * all of its counts are zero. The "universe" (total existing references) is derived from the
 * shared playset universe fetched from altered-core (variation standard, cached 1 hour) and
 * counted per faction × set after product-normalising references — so both endpoints agree.
 *
 * Some editions share the same cards and are merged into a single output set (see
 * {@see self::SET_ALIASES}): CORE and COREKS hold identical cards, so a card owned across both
 * is collapsed to one card whose quantity is the sum across editions (1×COREKS + 2×CORE counts
 * as a single card ×3, bucket "3+"). This merge happens at the card level — hence the service
 * works from raw per-card quantities and buckets them itself, rather than summing SQL buckets
 * which would double-count the same card. The merged set's universe is the canonical edition's
 * (CORE), since COREKS adds no new references.
 *
 * Likewise, a card printed in several products (the reference's 3rd token: B booster, A/P alt-art
 * or promo) is collapsed onto its booster (B) reference on both sides of the count, so owning an A
 * printing counts toward the same reference as the B — consistent with {@see CollectionPlaysetCardsService}.
 *
 * Both the universe lookup and the owned counts are restricted to a set of rarities — by default
 * {@see self::RARITIES}, or a caller-supplied subset of it — and to {@see self::CARD_TYPES}
 * (UNIQUE rarities, tokens and other card types are always excluded), so that the "0" bucket —
 * universe minus owned — stays internally consistent.
 *
 * The response carries three views of the same data:
 *   - "byFactionAndSet" — the full faction × set grid,
 *   - "byFaction"       — totals per faction, summed across all sets,
 *   - "bySet"           — totals per set, summed across all factions.
 * Each cardReference belongs to exactly one (faction, set), so the aggregates are plain sums
 * of the grid buckets with no double counting.
 */
class CollectionPlaysetService
{
    /** Card sets included in the playset breakdown, in output order. */
    public const SETS = ['CORE', 'ALIZE', 'BISE', 'CYCLONE', 'DUSTER', 'EOLE'];

    /**
     * Editions that hold the same cards as another set and are merged into it: source set code
     * (as stored on the cards) → output set code (must be one of {@see self::SETS}). Cards in a
     * source edition are folded onto their canonical edition before bucketing.
     */
    public const SET_ALIASES = [
        'COREKS' => 'CORE',
        'EOLECB' => 'EOLE',
        'WCQ25'  => 'CORE',
        'WCS26'  => 'DUSTER',
        'TCS3'   => 'BISE',
        'JUDGE'  => 'CORE',
    ];

    /**
     * Per-card aliases for editions where different cards fold onto different canonical sets —
     * {@see self::SET_ALIASES} can't express this, since it moves an entire source token onto one
     * target. Keyed by "SOURCE_SET|FACTION_NUM_SUFFIX" (the card's own faction, number and rarity
     * suffix tokens from its reference) → target set. Checked before {@see self::SET_ALIASES} in
     * {@see self::canonicalReference()}.
     *
     * `DUSTERTOP` ("Seeds of Unity — Box Topper") reprints chase cards pulled from CORE and ALIZE,
     * not from Duster itself. `MUSUBI` (a crossover promo mini-set) mostly reprints CORE cards with
     * a few from CYCLONE. Verified card-by-card (faction, number, rarity suffix, and name) against
     * every canonical set with zero ambiguous or unmatched cards.
     */
    public const CARD_ALIASES = [
        'DUSTERTOP|OR_08_R1' => 'CORE',
        'DUSTERTOP|OR_43_R1' => 'ALIZE',
        'DUSTERTOP|OR_14_C'  => 'CORE',
        'DUSTERTOP|OR_42_C'  => 'ALIZE',
        'DUSTERTOP|YZ_12_R1' => 'CORE',
        'DUSTERTOP|YZ_44_R1' => 'ALIZE',
        'DUSTERTOP|YZ_06_C'  => 'CORE',
        'DUSTERTOP|YZ_41_C'  => 'ALIZE',
        'DUSTERTOP|BR_32_C'  => 'ALIZE',
        'DUSTERTOP|BR_19_C'  => 'CORE',
        'DUSTERTOP|BR_38_R1' => 'ALIZE',
        'DUSTERTOP|BR_30_R1' => 'CORE',
        'DUSTERTOP|MU_13_C'  => 'CORE',
        'DUSTERTOP|MU_12_R1' => 'CORE',
        'DUSTERTOP|MU_44_C'  => 'ALIZE',
        'DUSTERTOP|MU_33_R1' => 'ALIZE',
        'DUSTERTOP|LY_04_R1' => 'CORE',
        'DUSTERTOP|LY_07_C'  => 'CORE',
        'DUSTERTOP|LY_39_R1' => 'ALIZE',
        'DUSTERTOP|LY_31_C'  => 'ALIZE',
        'DUSTERTOP|AX_04_C'  => 'CORE',
        'DUSTERTOP|AX_20_R1' => 'CORE',
        'DUSTERTOP|AX_32_R1' => 'ALIZE',
        'DUSTERTOP|AX_41_C'  => 'ALIZE',

        'MUSUBI|OR_66_R1' => 'CYCLONE',
        'MUSUBI|OR_09_R1' => 'CORE',
        'MUSUBI|OR_09_R2' => 'CORE',
        'MUSUBI|OR_09_C'  => 'CORE',
        'MUSUBI|YZ_09_C'  => 'CORE',
        'MUSUBI|YZ_09_R1' => 'CORE',
        'MUSUBI|YZ_09_R2' => 'CORE',
        'MUSUBI|YZ_21_C'  => 'CORE',
        'MUSUBI|BR_04_R1' => 'CORE',
        'MUSUBI|BR_04_C'  => 'CORE',
        'MUSUBI|BR_04_R2' => 'CORE',
        'MUSUBI|BR_74_R1' => 'CYCLONE',
        'MUSUBI|MU_70_R1' => 'CYCLONE',
        'MUSUBI|MU_22_R1' => 'CORE',
        'MUSUBI|MU_22_R2' => 'CORE',
        'MUSUBI|MU_22_C'  => 'CORE',
        'MUSUBI|LY_06_C'  => 'CORE',
        'MUSUBI|LY_06_R2' => 'CORE',
        'MUSUBI|LY_06_R1' => 'CORE',
        'MUSUBI|LY_29_R1' => 'CORE',
        'MUSUBI|AX_09_R1' => 'CORE',
        'MUSUBI|AX_09_C'  => 'CORE',
        'MUSUBI|AX_09_R2' => 'CORE',
        'MUSUBI|AX_30_R1' => 'CORE',
    ];

    /**
     * The distinct source-set tokens referenced by {@see self::CARD_ALIASES} (e.g. `DUSTERTOP`,
     * `MUSUBI`) — needed alongside {@see self::SET_ALIASES} keys when building the list of sets to
     * query owned quantities for, since a card aliased at the per-card level still needs its source
     * set included in that query.
     *
     * @return list<string>
     */
    public static function cardAliasSourceSets(): array
    {
        return array_values(array_unique(array_map(
            static fn (string $key): string => explode('|', $key)[0],
            array_keys(self::CARD_ALIASES),
        )));
    }

    /** Altered factions, in output order. */
    public const FACTIONS = ['AX', 'BR', 'LY', 'MU', 'OR', 'YZ'];

    /**
     * Rarities counted on both sides of the bucket-0 computation, and the full set of values a
     * caller may restrict the computation to.
     */
    public const RARITIES = ['COMMON', 'RARE', 'EXALTED'];

    /** Card types counted on both sides of the bucket-0 computation. */
    public const CARD_TYPES = ['CHARACTER', 'SPELL', 'PERMANENT', 'LANDMARK_PERMANENT', 'EXPEDITION_PERMANENT'];

    public function __construct(
        private readonly CollectionCardViewRepository $viewRepository,
        private readonly AlteredCoreClient            $alteredCoreClient,
    ) {}

    /**
     * @param  list<string>|null $rarities  Subset of {@see self::RARITIES} to compute on; null
     *                                       (the default) counts all of them.
     * @return array{
     *     byFactionAndSet: list<array{faction:string, cardSet:string, quantities:array{0:int, 1:int, 2:int, '3+':int}}>,
     *     byFaction: list<array{faction:string, quantities:array{0:int, 1:int, 2:int, '3+':int}}>,
     *     bySet: list<array{cardSet:string, quantities:array{0:int, 1:int, 2:int, '3+':int}}>
     * }
     */
    public function computePlayset(User $user, ?array $rarities = null): array
    {
        $rarities ??= self::RARITIES;

        // Query every underlying edition (the output sets plus their merged sources), then fold
        // each card onto its canonical edition and bucket the merged per-card quantities.
        $sourceSets     = array_values(array_unique(array_merge(
            self::SETS,
            array_keys(self::SET_ALIASES),
            self::cardAliasSourceSets(),
        )));
        $rows           = $this->viewRepository->findOwnedCardQuantities($user, $sourceSets, $rarities, self::CARD_TYPES);
        $owned          = $this->mergeAndBucket($rows);
        $universeCounts = $this->universeCountsByFactionAndSet($rarities);

        $byFactionAndSet = [];
        $factionTotals   = array_fill_keys(self::FACTIONS, ['0' => 0, '1' => 0, '2' => 0, '3+' => 0]);
        $setTotals       = array_fill_keys(self::SETS, ['0' => 0, '1' => 0, '2' => 0, '3+' => 0]);

        foreach (self::SETS as $set) {
            foreach (self::FACTIONS as $faction) {
                $buckets = $owned[$faction . '|' . $set] ?? ['1' => 0, '2' => 0, '3+' => 0];

                $ownedNonZero = $buckets['1'] + $buckets['2'] + $buckets['3+'];
                $universe     = $universeCounts[$faction . '|' . $set] ?? 0;

                $quantities = [
                    '0'  => max(0, $universe - $ownedNonZero),
                    '1'  => $buckets['1'],
                    '2'  => $buckets['2'],
                    '3+' => $buckets['3+'],
                ];

                $byFactionAndSet[] = [
                    'faction'    => $faction,
                    'cardSet'    => $set,
                    'quantities' => $quantities,
                ];

                foreach (['0', '1', '2', '3+'] as $bucket) {
                    $factionTotals[$faction][$bucket] += $quantities[$bucket];
                    $setTotals[$set][$bucket]         += $quantities[$bucket];
                }
            }
        }

        $byFaction = array_map(
            static fn (string $faction): array => ['faction' => $faction, 'quantities' => $factionTotals[$faction]],
            self::FACTIONS,
        );
        $bySet = array_map(
            static fn (string $set): array => ['cardSet' => $set, 'quantities' => $setTotals[$set]],
            self::SETS,
        );

        return [
            'byFactionAndSet' => $byFactionAndSet,
            'byFaction'       => $byFaction,
            'bySet'           => $bySet,
        ];
    }

    /**
     * Count the universe references per faction × set from the shared playset universe (variation
     * standard). References are product-normalised before counting, so the A/P printings of a card
     * collapse onto its booster (B) reference and are counted once — consistent with the owned side
     * and with {@see CollectionPlaysetCardsService}. The universe is queried for the canonical sets
     * only ({@see self::SETS}); COREKS adds no new references. Restricted to the requested rarity
     * subset so the "0" bucket stays consistent with the owned side.
     *
     * @param  list<string> $rarities
     * @return array<string, int>  "FACTION|CARDSET" => distinct reference count
     */
    private function universeCountsByFactionAndSet(array $rarities): array
    {
        $universe = $this->alteredCoreClient->fetchPlaysetUniverse(self::SETS, $rarities, self::CARD_TYPES);

        $seen   = [];
        $counts = [];
        foreach ($universe as $card) {
            $reference = $this->canonicalReference($card['reference']);
            if (isset($seen[$reference])) {
                continue; // another product of the same card already counted
            }
            $seen[$reference] = true;

            $faction = $card['faction']['code'] ?? null;
            $set     = $card['set']['reference'] ?? null;
            if ($faction === null || $set === null) {
                continue;
            }
            $counts[$faction . '|' . $set] = ($counts[$faction . '|' . $set] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * Fold owned cards onto their canonical edition, sum the quantity of each card across the
     * merged editions, then bucket the merged quantities per faction × (canonical) set.
     *
     * @param  list<array{faction:string, cardSet:string, cardReference:string, quantity:int}> $rows
     * @return array<string, array{1:int, 2:int, '3+':int}>  keyed by "FACTION|CARDSET"
     */
    private function mergeAndBucket(array $rows): array
    {
        // Sum each card's quantity across editions, keyed by its canonical (de-aliased) reference.
        $merged = [];
        foreach ($rows as $row) {
            $key = $this->canonicalReference($row['cardReference']);
            if (!isset($merged[$key])) {
                $merged[$key] = [
                    'faction'  => $row['faction'],
                    // Derived from the canonical reference itself (not a second SET_ALIASES lookup
                    // on the stored cardSet column) so CARD_ALIASES' per-card targets apply here too.
                    'cardSet'  => explode('_', $key)[1] ?? $row['cardSet'],
                    'quantity' => 0,
                ];
            }
            $merged[$key]['quantity'] += $row['quantity'];
        }

        $owned = [];
        foreach ($merged as $card) {
            if ($card['quantity'] <= 0) {
                continue;
            }
            $gridKey = $card['faction'] . '|' . $card['cardSet'];
            $owned[$gridKey] ??= ['1' => 0, '2' => 0, '3+' => 0];

            $bucket = match (true) {
                $card['quantity'] === 1 => '1',
                $card['quantity'] === 2 => '2',
                default                 => '3+',
            };
            $owned[$gridKey][$bucket]++;
        }

        return $owned;
    }

    /**
     * Rewrite a reference onto its canonical printing so every product/edition of the same card
     * collapses to one key: the set token is folded onto its canonical edition — per-card via
     * {@see self::CARD_ALIASES} first (e.g. DUSTERTOP's OR_08_R1 → CORE), then whole-token via
     * {@see self::SET_ALIASES} (COREKS → CORE) — and the product token (3rd) is normalised to the
     * booster product (B). ALT_COREKS_A_AX_01_C → ALT_CORE_B_AX_01_C. Mirrors
     * {@see CollectionPlaysetCardsService}.
     */
    private function canonicalReference(string $reference): string
    {
        $parts = explode('_', $reference);

        // parts: [ALT, SET, PRODUCT, FACTION, NUM, SUFFIX, ...]
        if (isset($parts[1])) {
            $cardKey = $parts[1] . '|' . ($parts[3] ?? '') . '_' . ($parts[4] ?? '') . '_' . ($parts[5] ?? '');
            if (isset(self::CARD_ALIASES[$cardKey])) {
                $parts[1] = self::CARD_ALIASES[$cardKey];
            } elseif (isset(self::SET_ALIASES[$parts[1]])) {
                $parts[1] = self::SET_ALIASES[$parts[1]];
            }
        }
        if (isset($parts[2])) {
            $parts[2] = 'B';
        }

        return implode('_', $parts);
    }
}
