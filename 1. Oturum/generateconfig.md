•⁠  ⁠Yapay zeka modeli olarak gemini-3-flash-preview modeli kullanılacak.
•⁠  ⁠generationConfig ayarları örnek olarak şu yapı baz alınarak belirlenecek, bu sadece bir örnek sadece formatı anlaman için:
"""        'generationConfig' => [
            'responseMimeType' => 'application/json',
            'responseSchema' => [
                'type' => 'OBJECT',
                'properties' => [
                    'title' => ['type' => 'STRING'],
                    'description' => ['type' => 'STRING'],
                    'keywords' => ['type' => 'STRING']
                ],
                'required' => ['title', 'description', 'keywords']
            ]
        ]"""
