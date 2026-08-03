<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumMeta;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Algeria's 58 wilayas — the one vocabulary for every `wilaya` column.
 *
 * Before Round 35 each resource picked its own representation: the branch form
 * hardcoded three of the 58 as a Select, while customers and car owners used a
 * free-text input that could store anything. All three now use this enum (see
 * the check constraints added by the 2026_08_19 wilaya migration).
 *
 * Values are accent-stripped slugs because they are persisted and matched
 * (`bejaia`, not `Béjaïa`); the display names — with accents — come from
 * getLabel(). Proper nouns, so they are not routed through the translation
 * files: "Béjaïa" is the same word in every locale.
 */
enum Wilaya: string implements HasColor, HasIcon, HasLabel
{
    use HasEnumMeta;

    case Adrar = 'adrar';
    case Chlef = 'chlef';
    case Laghouat = 'laghouat';
    case OumElBouaghi = 'oum_el_bouaghi';
    case Batna = 'batna';
    case Bejaia = 'bejaia';
    case Biskra = 'biskra';
    case Bechar = 'bechar';
    case Blida = 'blida';
    case Bouira = 'bouira';
    case Tamanrasset = 'tamanrasset';
    case Tebessa = 'tebessa';
    case Tlemcen = 'tlemcen';
    case Tiaret = 'tiaret';
    case TiziOuzou = 'tizi_ouzou';
    case Alger = 'alger';
    case Djelfa = 'djelfa';
    case Jijel = 'jijel';
    case Setif = 'setif';
    case Saida = 'saida';
    case Skikda = 'skikda';
    case Annaba = 'annaba';
    case Guelma = 'guelma';
    case Constantine = 'constantine';
    case Medea = 'medea';
    case Mostaganem = 'mostaganem';
    case Msila = 'msila';
    case Mascara = 'mascara';
    case Ouargla = 'ouargla';
    case Oran = 'oran';
    case ElBayadh = 'el_bayadh';
    case Illizi = 'illizi';
    case BordjBouArreridj = 'bordj_bou_arreridj';
    case Boumerdes = 'boumerdes';
    case ElTarf = 'el_tarf';
    case Tindouf = 'tindouf';
    case Tissemsilt = 'tissemsilt';
    case ElOued = 'el_oued';
    case Khenchela = 'khenchela';
    case SoukAhras = 'souk_ahras';
    case Tipaza = 'tipaza';
    case Mila = 'mila';
    case AinDefna = 'ain_defna';
    case Naama = 'naama';
    case AinTemouchent = 'ain_temouchent';
    case Ghardaia = 'ghardaia';
    case Relizane = 'relizane';
    case SidiBelAbbes = 'sidi_bel_abbes';
    case Timimoun = 'timimoun';
    case BordjBadjiMokhtar = 'bordj_badji_mokhtar';
    case OuledDjellal = 'ouled_djellal';
    case BeniAbbes = 'beni_abbes';
    case InSalah = 'in_salah';
    case InGuezzam = 'in_guezzam';
    case Touggourt = 'touggourt';
    case Djanet = 'djanet';
    case ElMghair = 'el_mghair';
    case ElMeniaa = 'el_meniaa';

    public function getLabel(): string
    {
        return match ($this) {
            self::Adrar => 'Adrar',
            self::Chlef => 'Chlef',
            self::Laghouat => 'Laghouat',
            self::OumElBouaghi => 'Oum El Bouaghi',
            self::Batna => 'Batna',
            self::Bejaia => 'Béjaïa',
            self::Biskra => 'Biskra',
            self::Bechar => 'Béchar',
            self::Blida => 'Blida',
            self::Bouira => 'Bouira',
            self::Tamanrasset => 'Tamanrasset',
            self::Tebessa => 'Tébessa',
            self::Tlemcen => 'Tlemcen',
            self::Tiaret => 'Tiaret',
            self::TiziOuzou => 'Tizi Ouzou',
            self::Alger => 'Alger',
            self::Djelfa => 'Djelfa',
            self::Jijel => 'Jijel',
            self::Setif => 'Sétif',
            self::Saida => 'Saïda',
            self::Skikda => 'Skikda',
            self::Annaba => 'Annaba',
            self::Guelma => 'Guelma',
            self::Constantine => 'Constantine',
            self::Medea => 'Médéa',
            self::Mostaganem => 'Mostaganem',
            self::Msila => "M'Sila",
            self::Mascara => 'Mascara',
            self::Ouargla => 'Ouargla',
            self::Oran => 'Oran',
            self::ElBayadh => 'El Bayadh',
            self::Illizi => 'Illizi',
            self::BordjBouArreridj => 'Bordj Bou Arreridj',
            self::Boumerdes => 'Boumerdès',
            self::ElTarf => 'El Tarf',
            self::Tindouf => 'Tindouf',
            self::Tissemsilt => 'Tissemsilt',
            self::ElOued => 'El Oued',
            self::Khenchela => 'Khenchela',
            self::SoukAhras => 'Souk Ahras',
            self::Tipaza => 'Tipaza',
            self::Mila => 'Mila',
            self::AinDefna => 'Aïn Defla',
            self::Naama => 'Naâma',
            self::AinTemouchent => 'Aïn Témouchent',
            self::Ghardaia => 'Ghardaïa',
            self::Relizane => 'Relizane',
            self::SidiBelAbbes => 'Sidi Bel Abbès',
            self::Timimoun => 'Timimoun',
            self::BordjBadjiMokhtar => 'Bordj Badji Mokhtar',
            self::OuledDjellal => 'Ouled Djellal',
            self::BeniAbbes => 'Béni Abbès',
            self::InSalah => 'In Salah',
            self::InGuezzam => 'In Guezzam',
            self::Touggourt => 'Touggourt',
            self::Djanet => 'Djanet',
            self::ElMghair => "El M'Ghair",
            self::ElMeniaa => 'El Meniaa',
        };
    }
}
