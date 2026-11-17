<?php

/*
 * Termos leigos de cada classe terapeutica da CMED.
 *
 * A planilha da Anvisa chama o grupo do Resfenol de "ANTIGRIPAIS SEM
 * ANTIINFECCIOSOS". Quem busca digita "gripe". Este arquivo e a ponte.
 *
 * A chave e um PREFIXO do codigo da classe, e os codigos sao hierarquicos:
 * "R" e aparelho respiratorio, "R5" tosse e gripe, "R5A" antigripais. Uma
 * classe herda os termos de todos os prefixos dela - R5A recebe os de "R5A",
 * e receberia tambem os de "R5" e "R" se existissem. Por isso ~130 linhas
 * cobrem as 541 classes, e classe nova da Anvisa ja nasce com os termos do
 * grupo acima dela.
 *
 * A Anvisa escreve o mesmo nivel de dois jeitos ("J7E1" e "J07A1"). Aqui vale
 * sempre a forma sem zero a esquerda; App\Support\GrupoCmed normaliza.
 *
 * Regras para editar:
 *   - minusculas, separados por virgula, como a pessoa digitaria;
 *   - o termo casa por palavra inteira: "dor" acha "dor de cabeca", nao "dormir";
 *   - depois de editar, rode `php artisan cmed:importar` (ou --so-termos).
 *
 * A primeira versao foi escrita por IA a partir dos nomes das classes. Vale a
 * revisao de quem conhece balcao de farmacia.
 */
return [
    // A - Aparelho digestivo e metabolismo
    'A1'   => 'boca, dente, dor de dente, afta',
    'A1B'  => 'sapinho, candidíase oral',
    'A2'   => 'estômago, azia, gastrite, queimação, má digestão',
    'A2A2' => 'gases',
    'A2A4' => 'gases',
    'A2B'  => 'refluxo, úlcera',
    'A3'   => 'cólica, dor de barriga, dor de estômago',
    'A3F'  => 'enjoo, náusea, refluxo, má digestão',
    'A4'   => 'enjoo, náusea, vômito',
    'A5'   => 'fígado, vesícula, ressaca',
    'A6'   => 'prisão de ventre, intestino preso, constipação, laxante',
    'A7'   => 'diarreia, intestino',
    'A7E'  => 'colite, doença de crohn',
    'A7F'  => 'probiótico, flora intestinal',
    'A7G'  => 'desidratação, reidratação, soro',
    'A8'   => 'emagrecer, emagrecimento, obesidade, perda de peso',
    'A9'   => 'digestão, má digestão',
    'A10'  => 'diabetes, glicose, açúcar no sangue',
    'A10C' => 'insulina',
    'A11'  => 'vitamina, suplemento',
    'A12'  => 'mineral, suplemento',
    'A13'  => 'tônico, fortificante, cansaço',
    'A14'  => 'anabolizante',
    'A15'  => 'apetite, abrir o apetite',

    // B - Sangue
    'B1'   => 'anticoagulante, trombose, afinar o sangue, coágulo',
    'B2'   => 'hemorragia, sangramento, coagulação',
    'B3'   => 'anemia, ferro, ácido fólico',

    // C - Cardiovascular
    'C1'   => 'coração, arritmia, angina',
    'C2'   => 'pressão alta, hipertensão',
    'C3'   => 'diurético, inchaço, retenção de líquido, pressão alta',
    'C4'   => 'circulação',
    'C5'   => 'varizes, circulação, pernas cansadas',
    'C5A'  => 'hemorroida',
    'C6B'  => 'hipertensão pulmonar',
    'C7'   => 'pressão alta, hipertensão, coração',
    'C8'   => 'pressão alta, hipertensão',
    'C9'   => 'pressão alta, hipertensão',
    'C10'  => 'colesterol, triglicerídeos',
    'C11'  => 'colesterol',

    // D - Dermatologia
    'D1'   => 'micose, fungo, frieira, pé de atleta',
    'D1A3' => 'caspa, dermatite seborreica',
    'D2'   => 'pele seca, assadura',
    'D3'   => 'ferida, machucado, cicatrizante',
    'D4'   => 'coceira, picada de inseto',
    'D5'   => 'psoríase',
    'D5X'  => 'dermatite, eczema',
    'D6'   => 'infecção de pele, pomada antibiótica',
    'D6D'  => 'herpes, herpes labial',
    'D7'   => 'alergia de pele, dermatite, coceira, pomada de corticoide',
    'D8'   => 'antisséptico, ferida',
    'D10'  => 'acne, espinha, cravo',

    'FITO' => 'fitoterápico, planta medicinal, natural',

    // G - Geniturinario e hormonios sexuais
    'G1'   => 'corrimento, candidíase, infecção vaginal',
    'G2D'  => 'secar o leite, prolactina',
    'G3'   => 'hormônio',
    'G3A'  => 'anticoncepcional, pílula, contraceptivo',
    'G3A6' => 'pílula do dia seguinte',
    'G3B'  => 'testosterona',
    'G3C'  => 'menopausa, reposição hormonal',
    'G3D'  => 'progesterona',
    'G3F'  => 'menopausa, reposição hormonal',
    'G3G'  => 'fertilidade, ovulação',
    'G4A'  => 'infecção urinária, cistite',
    'G4B'  => 'incontinência urinária, bexiga',
    'G4C'  => 'próstata',
    'G4D'  => 'incontinência urinária, bexiga',
    'G4E'  => 'disfunção erétil, impotência, ereção',

    // H - Hormonios sistemicos
    'H2'   => 'corticoide, inflamação, alergia',
    'H3'   => 'tireoide',
    'H3A'  => 'hipotireoidismo',
    'H3B'  => 'hipertireoidismo',
    'H4C'  => 'hormônio do crescimento',

    // J - Anti-infecciosos
    'J1'   => 'antibiótico, infecção',
    'J2'   => 'micose, fungo, candidíase',
    'J4'   => 'tuberculose',
    'J5'   => 'antiviral, vírus',
    'J5B1' => 'hepatite',
    'J5B3' => 'herpes',
    'J5B4' => 'gripe, influenza',
    'J5C'  => 'hiv',
    'J5D'  => 'hepatite',
    'J6'   => 'imunoglobulina, soro',
    'J7'   => 'vacina',
    'J7A1' => 'gripe, influenza',
    'J7A7' => 'pneumonia',
    'J7D1' => 'pneumonia',
    'J7D2' => 'meningite',
    'J7E1' => 'gripe, influenza',
    'J7E2' => 'catapora',
    'J7E3' => 'hpv',
    'J7E4' => 'hepatite',

    'K'    => 'soro, solução hospitalar',

    // L - Oncologia e imunologia
    'L1'   => 'câncer, quimioterapia',
    'L2'   => 'câncer, hormonioterapia',
    'L3'   => 'imunidade',
    'L4'   => 'imunossupressor, artrite reumatoide, transplante',

    // M - Musculoesqueletico
    'M1'   => 'dor, inflamação, anti-inflamatório, dor muscular, dor nas costas',
    'M1C'  => 'artrite reumatoide',
    'M2'   => 'dor muscular, contusão, torcicolo, pomada para dor',
    'M3'   => 'relaxante muscular, dor muscular, torcicolo, dor nas costas',
    'M4'   => 'gota, ácido úrico',
    'M5'   => 'osteoporose, ossos',
    'M5X'  => 'artrose, articulação, cartilagem',

    // N - Sistema nervoso
    'N1'   => 'anestésico',
    'N2A'  => 'dor forte, opioide',
    'N2B'  => 'dor, dor de cabeça, febre, analgésico, antitérmico',
    'N2C'  => 'enxaqueca, dor de cabeça',
    'N2D'  => 'dor neuropática, fibromialgia',
    'N3'   => 'epilepsia, convulsão',
    'N4'   => 'parkinson',
    'N5A'  => 'esquizofrenia, antipsicótico, bipolar',
    'N5B'  => 'insônia, dormir, sono',
    'N5C'  => 'ansiedade, calmante',
    'N6A'  => 'depressão, ansiedade, antidepressivo',
    'N6A3' => 'bipolar',
    'N6B'  => 'tdah, déficit de atenção',
    'N6D'  => 'memória',
    'N7A'  => 'esclerose múltipla',
    'N7B'  => 'parar de fumar, tabagismo, cigarro',
    'N7C'  => 'labirintite, tontura, vertigem',
    'N7D'  => 'alzheimer, memória',
    'N7E'  => 'alcoolismo',

    // P - Antiparasitarios
    'P1'   => 'verme, vermífugo, parasita',
    'P1D'  => 'malária',
    'P3'   => 'piolho, sarna',

    // R - Respiratorio
    'R1'   => 'nariz entupido, congestão nasal, rinite, sinusite',
    'R2'   => 'dor de garganta, garganta inflamada, pastilha',
    'R3'   => 'asma, bronquite, falta de ar, bombinha',
    'R4'   => 'gripe, resfriado, nariz entupido',
    'R5A'  => 'gripe, resfriado, coriza, antigripal',
    'R5C'  => 'tosse, catarro, expectorante, xarope',
    'R5D'  => 'tosse, tosse seca, xarope',
    'R6'   => 'alergia, rinite, antialérgico, coceira',
    'R7'   => 'pulmão',

    // S - Olhos e ouvidos
    'S1'   => 'olhos, colírio',
    'S1A'  => 'conjuntivite',
    'S1E'  => 'glaucoma',
    'S1G'  => 'alergia nos olhos, olho vermelho',
    'S1K'  => 'olho seco, lágrima artificial',
    'S2'   => 'ouvido, dor de ouvido, otite',
    'S2D'  => 'cera de ouvido',

    'T'    => 'diagnóstico, contraste',
];
