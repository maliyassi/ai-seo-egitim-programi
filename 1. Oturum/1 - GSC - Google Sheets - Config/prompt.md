Aşağıdaki GSC verisini analiz et ve 
şu grupları JSON formatında döndür:

1. sge_riski: 
   Gösterim > 10.000 VE CTR < %1
   (alan: url, impressions, ctr, position)

2. hizli_kazanc: 
   Pozisyon 4-10 arası VE gösterim > 5.000
   (alan: url, impressions, ctr, position)

3. scale_edilebilir: 
   CTR > %15 VE gösterim < 500
   (alan: url, ctr, impressions)

4. ilk_sayfa_siniri: 
   Pozisyon 8-12 arası VE gösterim > 3.000
   (alan: url, position, impressions)

5. duplicate_url_suptesi: 
   Benzer URL pattern'ları 
   (trailing slash farkı veya 
   aynı slug farklı path)
   (alan: url_1, url_2, impressions_1, 
   impressions_2)

Her grup için:
- Toplam kayıt sayısı
- En kritik 10 örnek
- Grup için 1 cümlelik aksiyon önerisi

generationConfig:
responseMimeType: application/json
responseSchema:
  type: OBJECT
  properties:
    sge_riski:
      type: ARRAY
      items:
        type: OBJECT
        properties:
          url: {type: STRING}
          impressions: {type: INTEGER}
          ctr: {type: NUMBER}
          position: {type: NUMBER}
          aksiyon: {type: STRING}
    hizli_kazanc:
      type: ARRAY
      items:
        type: OBJECT
        properties:
          url: {type: STRING}
          impressions: {type: INTEGER}
          ctr: {type: NUMBER}
          position: {type: NUMBER}
    scale_edilebilir:
      type: ARRAY
      items:
        type: OBJECT
        properties:
          url: {type: STRING}
          ctr: {type: NUMBER}
          impressions: {type: INTEGER}
    ilk_sayfa_siniri:
      type: ARRAY
      items:
        type: OBJECT
        properties:
          url: {type: STRING}
          position: {type: NUMBER}
          impressions: {type: INTEGER}
    duplicate_url_suphe:
      type: ARRAY
      items:
        type: OBJECT
        properties:
          url_1: {type: STRING}
          url_2: {type: STRING}
  required: 
    [sge_riski, hizli_kazanc, 
     scale_edilebilir, ilk_sayfa_siniri,
     duplicate_url_suphe]
