<?php

/*
 * Language-specific complexity signals for the SmartRoutingMiddleware.
 *
 * Structured by language code (ISO 639-1), so extensions can add or
 * replace signals for individual languages without affecting others.
 *
 * Extensions can ship their own file at the same path:
 *   Configuration/SmartRouting/ComplexitySignals.php
 * AiM discovers and merges all at boot time per language key.
 *
 * Alternatively, add signals at runtime via ext_localconf.php:
 *   $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['aim']['complexitySignals']['ja']['complex'][] = '比較して';
 *
 * Each language has three signal types:
 *   'complex'   => Phrases indicating complex prompts (score +0.25, matched anywhere)
 *   'simple'    => Phrases indicating simple prompts (score -0.25, matched at prompt start)
 *   'multiPart' => Connectors signaling multi-part questions (score +0.15, matched anywhere)
 */

return [
    'en' => [
        'complex' => [
            'compare', 'contrast', 'design', 'architect', 'optimize',
            'implement', 'refactor', 'evaluate', 'trade-off', 'tradeoff',
            'step by step', 'in detail', 'comprehensive', 'algorithm',
            'best practices', 'security implications', 'pros and cons',
        ],
        'simple' => [
            'what is', 'define', 'translate', 'list', 'hello', 'hi',
            'yes', 'no', 'thanks', 'ok',
        ],
        'multiPart' => [
            ' vs ', ' versus ', ' pros and cons ',
        ],
    ],
    'de' => [
        'complex' => [
            'vergleiche', 'analysiere', 'entwerfe', 'optimiere', 'implementiere',
            'refaktoriere', 'evaluiere', 'vor- und nachteile', 'schritt für schritt',
            'im detail', 'umfassend', 'architektur', 'sicherheitsaspekte',
        ],
        'simple' => [
            'was ist', 'definiere', 'übersetze', 'liste', 'hallo',
            'ja', 'nein', 'danke',
        ],
        'multiPart' => [
            ' gegen ', ' im vergleich zu ',
        ],
    ],
    'fr' => [
        'complex' => [
            'comparez', 'analysez', 'concevez', 'optimisez', 'implémentez',
            'évaluez', 'avantages et inconvénients', 'étape par étape',
            'en détail', 'meilleures pratiques',
        ],
        'simple' => [
            "qu'est-ce que", 'définir', 'traduire', 'lister', 'bonjour',
            'oui', 'non', 'merci',
        ],
        'multiPart' => [
            ' contre ', ' par rapport à ',
        ],
    ],
    'es' => [
        'complex' => [
            'compara', 'analiza', 'diseña', 'optimiza', 'implementa',
            'evalúa', 'ventajas y desventajas', 'paso a paso',
            'en detalle', 'mejores prácticas',
        ],
        'simple' => [
            'qué es', 'definir', 'traducir', 'listar', 'hola',
            'sí', 'no', 'gracias',
        ],
        'multiPart' => [
            ' contra ', ' en comparación con ',
        ],
    ],
    'nl' => [
        'complex' => [
            'vergelijk', 'analyseer', 'ontwerp', 'optimaliseer', 'implementeer',
            'evalueer', 'voor- en nadelen', 'stap voor stap', 'in detail',
        ],
        'simple' => [
            'wat is', 'definieer', 'vertaal', 'lijst', 'hallo',
            'ja', 'nee', 'bedankt',
        ],
        'multiPart' => [
            ' tegen ', ' in vergelijking met ',
        ],
    ],
];
