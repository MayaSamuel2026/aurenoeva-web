#!/usr/bin/env python3
"""Build the approved AURENOEVA German one-page brochure from existing brand artwork.
Deterministic; no external image source, no additional brochure infrastructure.
"""
from pathlib import Path
import os
from PIL import Image, ImageDraw
from reportlab.pdfgen import canvas
from reportlab.lib.pagesizes import A4
from reportlab.lib.colors import HexColor
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.platypus import Paragraph
from reportlab.lib.styles import ParagraphStyle
from reportlab.lib.enums import TA_CENTER

ROOT = Path(__file__).resolve().parents[1]
ASSETS = ROOT / "deployment/site/procurement/assets"
OUT = ASSETS / "AURENOEVA_Firmenbroschuere_Deutsch.pdf"
TMP = Path(os.environ.get("RUNNER_TEMP", "/tmp"))
font_dir = Path("/usr/share/fonts/truetype/lato")
fallback = Path("/usr/share/fonts/truetype/dejavu")
font_files = {"Lato":"Lato-Regular.ttf","Lato-B":"Lato-Bold.ttf","Lato-I":"Lato-Italic.ttf"}
for name, filename in font_files.items():
    p = font_dir / filename
    if not p.is_file():
        p = fallback / ("DejaVuSans-Bold.ttf" if name=="Lato-B" else
                        "DejaVuSans-Oblique.ttf" if name=="Lato-I" else "DejaVuSans.ttf")
    pdfmetrics.registerFont(TTFont(name,str(p)))
ref = Image.open(ASSETS / "AURENOEVA_Company_Introduction.png").convert("RGB")
ref = ref.resize((1055,1491),Image.Resampling.LANCZOS)
logo = ref.crop((50,20,550,120))
ImageDraw.Draw(logo).rectangle((121,76,500,100),fill=(252,251,246))
logo_path = TMP / "aurenoeva_de_logo.png"
logo.save(logo_path)
photo = ref.crop((615,0,1024,472)).convert("RGBA")
mask=Image.new("L",photo.size)
px=mask.load()
for y in range(photo.height):
    for x in range(photo.width):
        alpha=max(0,min(255,int(255*max(0,min(1,(x-1)/(photo.width*.33))))))
        px[x,y]=int(alpha*min(1.0,max(0.0,(photo.height-y)/65)))
photo.putalpha(mask)
hero=TMP/"aurenoeva_de_hero.png";photo.save(hero)
icons=[]
for i,x in enumerate([51,244,435,627,817],1):
    icon=ref.crop((x,704,x+162,855)).convert("RGBA")
    pixels=icon.load()
    for yy in range(icon.height):
        for xx in range(icon.width):
            r,g,b,a=pixels[xx,yy]
            if min(r,g,b)>220 and max(r,g,b)-min(r,g,b)<33: a=0
            elif min(r,g,b)>203 and max(r,g,b)-min(r,g,b)<33:
                a=max(0,min(230,int((222-min(r,g,b))*11)))
            pixels[xx,yy]=(r,g,b,a)
    p=TMP/f"aurenoeva_de_step_{i}.png";icon.save(p);icons.append(p)

W,H=A4
cream=HexColor("#FCFBF6");dark=HexColor("#073F32");green=HexColor("#145C48")
gold=HexColor("#BD9A46");ink=HexColor("#21352F")
c=canvas.Canvas(str(OUT),pagesize=A4,pageCompression=1,invariant=1)
c.setTitle("AURENOEVA | Unternehmensvorstellung")
c.setAuthor("AURENOEVA - NOEVA Systems e.K.")
c.setFillColor(cream);c.rect(0,0,W,H,stroke=0,fill=1)
c.drawImage(str(hero),W-242,H-274,width=242,height=274,mask="auto")
c.setFillColor(dark)
p=c.beginPath();p.moveTo(W-38,H);p.lineTo(W,H);p.lineTo(W,H-67);p.close();c.drawPath(p,fill=1,stroke=0)
c.setStrokeColor(gold);c.line(W-32,H,W-74,H-70)
c.drawImage(str(logo_path),30,H-70,width=283,height=46,mask="auto")
c.setFillColor(dark);c.setFont("Lato-B",6.65)
c.drawString(116,H-75,"BESCHAFFUNG  ·  KOMPONENTEN  ·  MARKTWISSEN")
c.setFont("Lato-B",21.6)
for i,line in enumerate(["Verlässliche Beschaffung","von Ersatzteilen, Geräten","und technischen Produkten."]):
    c.drawString(30,H-119-i*27,line)
c.setStrokeColor(gold);c.setLineWidth(2);c.line(30,H-201,75,H-201)
def para(txt,x,y,width,size=9.45,leading=13.5,font="Lato",align=0):
    st=ParagraphStyle("p",fontName=font,fontSize=size,leading=leading,textColor=ink,alignment=align)
    p=Paragraph(txt,st);_,h=p.wrap(width,130);p.drawOn(c,x,y-h);return h
para("Von Deutschland aus verbinden wir Kunden mit geeigneten Bezugsquellen und fördern langfristige Geschäftsbeziehungen auf Vertrauensbasis.",
     30,H-217,318,10.3,14.1,"Lato-I")
c.setFillColor(dark);c.setFont("Lato-B",17.5);c.drawString(30,578,"Was wir tun")
c.setStrokeColor(gold);c.setLineWidth(1.2);c.line(151,584,187,584)
para("AURENOEVA betreibt einen Online-Katalog für Ersatzteile, Komponenten und technische Produkte. "
     "Interessenten können gezielt suchen und anfragen. Darüber hinaus bearbeiten wir individuelle Angebotsanfragen (RFQs). "
     "Wir identifizieren geeignete Bezugsquellen, bündeln relevante Lieferanten- und Produktinformationen und stellen "
     "diese dem Kunden zur Verfügung. Der Kunde bestellt direkt beim Lieferanten; AURENOEVA erhält eine Provision vom Lieferanten.",
     30,566,W-62)
x0=20;y0=244;ww=W-40;hh=212
c.setFillColor(HexColor("#FDFCF8"));c.setStrokeColor(HexColor("#DCD8BE"))
c.roundRect(x0,y0,ww,hh,14,fill=1,stroke=1)
c.setFillColor(dark);c.setFont("Lato-B",18.2);c.drawString(x0+15,y0+hh-29,"So funktioniert die Vermittlung")
c.setStrokeColor(gold);c.line(x0+305,y0+hh-23,x0+341,y0+hh-23)
steps=[
"Anfrage über<br/>Katalog oder RFQ",
"Bezugsquellen<br/>ermitteln und<br/>prüfen",
"Lieferanten und<br/>Angebotsoptionen<br/>vorstellen",
"Kauf direkt<br/>beim Lieferanten",
"Provision und<br/>langfristige<br/>Partnerschaft"]
for i,(label,icon) in enumerate(zip(steps,icons)):
    cx=x0+62+i*110
    c.drawImage(str(icon),cx-43,y0+78,width=86,height=81,mask="auto")
    para(label,cx-54,y0+67,108,9.0,11.7,"Lato",TA_CENTER)
c.setFillColor(dark);c.setFont("Lato-B",17.5);c.drawString(30,217,"Warum AURENOEVA?")
c.setStrokeColor(gold);c.line(222,223,254,223)
why=[
"Beschaffungspartner mit Sitz in Deutschland",
"Identifikation geeigneter Lieferanten",
"Direkter Kauf zwischen Kunde und Lieferant",
"Langfristige Zusammenarbeit auf Vertrauensbasis",
"Effiziente Bearbeitung von Kataloganfragen und RFQs"]
for i,txt in enumerate(why):
    y=190-i*27.9
    c.setFillColor(HexColor("#ECEDE4"));c.roundRect(30,y-7,20,20,5,stroke=0,fill=1)
    c.setFillColor(green);c.setFont("Lato-B",11);c.drawCentredString(40,y,str(i+1))
    c.setFillColor(ink);c.setFont("Lato",9.3);c.drawString(58,y+1,txt)
    if i<4:
        c.setStrokeColor(HexColor("#DDDCD1"));c.setLineWidth(.45);c.line(58,y-11,308,y-11)
cx=320;cy=47;cw=255;ch=184
c.setFillColor(dark);c.roundRect(cx,cy,cw,ch,16,stroke=0,fill=1)
c.setFillColor(HexColor("#F5E9C1"));c.setFont("Lato-B",16.7);c.drawString(cx+20,cy+ch-31,"Unternehmensangaben")
c.setFillColor(HexColor("#FFFFFF"));c.setFont("Lato-B",12.1);c.drawString(cx+20,cy+ch-54,"AURENOEVA")
c.setFont("Lato",9.2);c.drawString(cx+20,cy+ch-68,"Ein Projekt der NOEVA Systems e.K.")
for i,t in enumerate([
"Marienburger Straße 16","56112 Lahnstein · Deutschland",
"Amtsgericht Koblenz · HRA 23554","info@aurenoeva.com","www.aurenoeva.com"]):
    c.setFont("Lato",9.05);c.drawString(cx+20,cy+ch-90-i*15,t)
c.setFillColor(green)
p=c.beginPath();p.moveTo(0,0);p.lineTo(0,15);p.lineTo(60,0);p.close();c.drawPath(p,fill=1,stroke=0)
c.setFillColor(gold)
p=c.beginPath();p.moveTo(W,0);p.lineTo(W,19);p.lineTo(W-65,0);p.close();c.drawPath(p,fill=1,stroke=0)
c.showPage();c.save()
print(str(OUT))
