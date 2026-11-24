<?php

session_start();

include __DIR__ . '/../includes/db.php';


/* =========================================================
   ADMIN AUTHENTICATION
   ========================================================= */

if (!isset($_SESSION['user_id'])) {

    header("Location: ../login.php");
    exit;

}

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>Walk-in Booking | Mizpah Wellness Spa</title>

<link
    href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600;700&family=Poppins:wght@300;400;500;600&display=swap"
    rel="stylesheet"
>

<style>

/* =========================================================
   RESET
   ========================================================= */

*{
    margin:0;
    padding:0;
    box-sizing:border-box;
}

body{

    min-height:100vh;

    background:#0b0b0b;

    color:#fff;

    font-family:'Poppins',sans-serif;

}


/* =========================================================
   BACKGROUND
   ========================================================= */

body::before{

    content:"";

    position:fixed;

    top:-250px;
    right:-200px;

    width:500px;
    height:500px;

    background:
        rgba(214,194,156,.045);

    filter:blur(120px);

    pointer-events:none;

}


/* =========================================================
   HEADER
   ========================================================= */

.header{

    position:sticky;

    top:0;

    z-index:1000;

    min-height:68px;

    display:flex;

    align-items:center;

    justify-content:space-between;

    gap:20px;

    padding:11px 5%;

    background:
        rgba(11,11,11,.96);

    border-bottom:
        1px solid
        rgba(214,194,156,.12);

    backdrop-filter:blur(15px);

}

.brand{

    display:flex;

    align-items:center;

    gap:11px;

}

.logo{

    width:42px;
    height:42px;

    object-fit:contain;

}

.brand-text small{

    display:block;

    color:#777;

    font-size:8px;

    text-transform:uppercase;

    letter-spacing:1.5px;

}

.brand-text strong{

    color:#D6C29C;

    font-family:
        'Playfair Display',
        serif;

    font-size:17px;

    font-weight:600;

}

.back-btn{

    padding:8px 13px;

    border:
        1px solid
        rgba(214,194,156,.25);

    border-radius:7px;

    color:#aaa;

    text-decoration:none;

    font-size:9px;

    transition:.2s;

}

.back-btn:hover{

    color:#111;

    background:#D6C29C;

}


/* =========================================================
   HERO
   ========================================================= */

.hero{

    padding:
        45px 5%
        30px;

    text-align:center;

}

.hero-label{

    color:#7d705a;

    font-size:8px;

    text-transform:uppercase;

    letter-spacing:2.5px;

    margin-bottom:7px;

}

.hero h1{

    color:#D6C29C;

    font-family:
        'Playfair Display',
        serif;

    font-size:
        clamp(27px,4vw,38px);

    font-weight:600;

    margin-bottom:7px;

}

.hero p{

    color:#777;

    font-size:10px;

    line-height:1.7;

}


/* =========================================================
   MAIN LAYOUT
   ========================================================= */

.page{

    width:100%;

    max-width:1180px;

    margin:auto;

    padding:
        15px 4%
        70px;

}

.booking-layout{

    display:grid;

    grid-template-columns:
        minmax(0,1fr)
        300px;

    gap:18px;

    align-items:start;

}

.form-column{

    display:flex;

    flex-direction:column;

    gap:14px;

}


/* =========================================================
   SECTION BOX
   ========================================================= */

.box{

    background:
        linear-gradient(
            145deg,
            #141414,
            #101010
        );

    border:
        1px solid
        rgba(255,255,255,.065);

    border-radius:14px;

    padding:20px;

}

.step-header{

    display:flex;

    align-items:flex-start;

    gap:11px;

    margin-bottom:17px;

}

.step-number{

    width:27px;
    height:27px;

    flex-shrink:0;

    display:flex;

    align-items:center;
    justify-content:center;

    border-radius:50%;

    background:
        rgba(214,194,156,.10);

    border:
        1px solid
        rgba(214,194,156,.22);

    color:#D6C29C;

    font-size:9px;

    font-weight:600;

}

.step-header h2{

    color:#D6C29C;

    font-family:
        'Playfair Display',
        serif;

    font-size:17px;

    font-weight:600;

    margin-bottom:2px;

}

.step-header p{

    color:#666;

    font-size:8px;

    line-height:1.5;

}


/* =========================================================
   SUB LABEL
   ========================================================= */

.field-label{

    display:block;

    margin:
        16px 0
        7px;

    color:#888;

    font-size:8px;

    font-weight:500;

    text-transform:uppercase;

    letter-spacing:1px;

}

.field-label:first-child{

    margin-top:0;

}


/* =========================================================
   OPTION GRID
   ========================================================= */

.grid{

    display:grid;

    grid-template-columns:
        repeat(
            auto-fit,
            minmax(130px,1fr)
        );

    gap:8px;

}


/* =========================================================
   CARDS
   ========================================================= */

.card{

    position:relative;

    min-height:48px;

    padding:11px;

    display:flex;

    flex-direction:column;

    align-items:center;

    justify-content:center;

    text-align:center;

    background:#0f0f0f;

    border:
        1px solid
        #262626;

    border-radius:9px;

    color:#bbb;

    font-size:9px;

    line-height:1.5;

    cursor:pointer;

    transition:.2s;

    user-select:none;

}

.card:hover{

    border-color:
        rgba(214,194,156,.35);

    color:#D6C29C;

    transform:
        translateY(-1px);

}

.card.duration-locked{
    cursor:default !important;
    pointer-events:none !important;
    opacity:1;
}

.card.active{

    border-color:#D6C29C;

    background:
        rgba(214,194,156,.075);

    color:#D6C29C;

    box-shadow:
        inset 0 0 0 1px
        rgba(214,194,156,.10);

}

.card.dim{

    opacity:.28;

    cursor:not-allowed;

    pointer-events:none;

    transform:none;

}

.small{

    margin-top:3px;

    color:#666;

    font-size:7px;

    line-height:1.4;

}


/* =========================================================
   THERAPIST UNAVAILABLE
   ========================================================= */

.card.therapist.unavailable{

    opacity:.30;

    cursor:not-allowed;

    pointer-events:none;

    border-color:#242424;

    color:#777;

    transform:none;

}

.card.therapist.unavailable .small{

    color:#744f4f;

}


/* =========================================================
   SERVICE DESCRIPTION
   ========================================================= */

#serviceDesc{

    margin-top:9px;

    color:#777;

    font-size:9px;

    line-height:1.6;

}


/* =========================================================
   INPUTS
   ========================================================= */

input,
select,
textarea{

    width:100%;

    padding:11px 12px;

    background:#0d0d0d;

    color:#ddd;

    border:
        1px solid
        #292929;

    border-radius:8px;

    outline:none;

    font-family:'Poppins',sans-serif;

    font-size:9px;

    transition:.2s;

}

input:focus,
select:focus,
textarea:focus{

    border-color:
        rgba(214,194,156,.55);

    box-shadow:
        0 0 0 2px
        rgba(214,194,156,.05);

}

input::placeholder,
textarea::placeholder{

    color:#555;

}

textarea{

    min-height:90px;

    resize:vertical;

}

input[type="date"]{
    color-scheme:dark;
    position:relative;
    z-index:5;
    pointer-events:auto !important;
    cursor:pointer;
    min-height:42px;
    width:100%;
}

input[type="date"]::-webkit-calendar-picker-indicator{
    cursor:pointer;
    opacity:1;
    filter:invert(.82) sepia(.18) saturate(.55);
}


/* =========================================================
   CUSTOMER GRID
   ========================================================= */

.customer-grid{

    display:grid;

    grid-template-columns:
        repeat(2,1fr);

    gap:10px;

}

.full-field{

    grid-column:1/-1;

}


/* =========================================================
   SUMMARY
   ========================================================= */

.summary{

    position:sticky;

    top:86px;

    background:
        linear-gradient(
            145deg,
            #151515,
            #101010
        );

    border:
        1px solid
        rgba(214,194,156,.16);

    border-radius:14px;

    padding:20px;

}

.summary-label{

    color:#6f634f;

    font-size:7px;

    text-transform:uppercase;

    letter-spacing:2px;

    margin-bottom:4px;

}

.summary h3{

    color:#D6C29C;

    font-family:
        'Playfair Display',
        serif;

    font-size:19px;

    margin-bottom:18px;

}

.summary-row{

    display:flex;

    justify-content:space-between;

    align-items:flex-start;

    gap:15px;

    padding:
        9px 0;

    border-bottom:
        1px solid
        rgba(255,255,255,.045);

}

.summary-row span:first-child{

    color:#666;

    font-size:8px;

}

.summary-row span:last-child{

    color:#bbb;

    font-size:8px;

    text-align:right;

    word-break:break-word;

}

.summary-addons{

    margin-top:14px;

    padding:
        10px;

    background:
        rgba(255,255,255,.02);

    border-radius:8px;

}

.summary-addons-title{

    color:#666;

    font-size:7px;

    text-transform:uppercase;

    letter-spacing:1px;

    margin-bottom:5px;

}

#summaryAddons{

    color:#888;

    font-size:8px;

    line-height:1.6;

}


/* =========================================================
   TOTAL
   ========================================================= */

.total-box{

    display:flex;

    align-items:flex-end;

    justify-content:space-between;

    gap:15px;

    margin-top:16px;

    padding-top:14px;

    border-top:
        1px solid
        rgba(214,194,156,.15);

}

.total-label{

    color:#777;

    font-size:8px;

}

.total-price{

    color:#D6C29C;

    font-family:
        'Playfair Display',
        serif;

    font-size:23px;

    font-weight:600;

}


/* =========================================================
   CONFIRM BUTTON
   ========================================================= */

.btn{

    width:100%;

    margin-top:16px;

    padding:12px 15px;

    border:none;

    border-radius:8px;

    background:#D6C29C;

    color:#111;

    font-family:'Poppins',sans-serif;

    font-size:9px;

    font-weight:600;

    cursor:pointer;

    transition:.2s;

}

.btn:hover{

    background:#ead8b1;

    transform:
        translateY(-1px);

}

.admin-note{

    margin-top:10px;

    color:#555;

    font-size:7px;

    line-height:1.5;

    text-align:center;

}


/* =========================================================
   PAX NOTE
   ========================================================= */

.day-availability{display:grid;grid-template-columns:repeat(auto-fill,minmax(125px,1fr));gap:8px;margin:12px 0 18px}
.day-slot{border:1px solid #6b5637;border-radius:8px;padding:9px 10px;text-align:center;font-size:13px;background:#211b12;color:#f7f0e4;cursor:pointer}
.day-slot .slot-status{display:block;font-size:11px;margin-top:4px}
.day-slot.available{border-color:#6d9568}
.day-slot.available .slot-status{color:#9bd294}
.day-slot.occupied,.day-slot.past{opacity:.58;cursor:not-allowed;border-color:#8b5750}
.day-slot.occupied .slot-status,.day-slot.past .slot-status{color:#e5a19a}
.day-slot:disabled{pointer-events:none}

.day-availability{margin-top:12px;padding:12px;border:1px solid #30281c;border-radius:12px;background:linear-gradient(180deg,#17130e,#100e0b);box-shadow:inset 0 1px 0 rgba(255,255,255,.025)}
.day-availability:empty{display:none}

.day-availability{display:block!important}
.day-availability-group + .day-availability-group{margin-top:18px;padding-top:15px;border-top:1px solid rgba(214,194,156,.14)}
.day-availability-heading{margin:0 0 10px;color:#D6C29C;font-family:Poppins,sans-serif;font-size:11px;font-weight:600;letter-spacing:.35px}
.day-availability-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(125px,1fr));gap:8px}

.day-slot{min-height:58px;border:1px solid #30281c;border-radius:10px;padding:8px 7px;text-align:center;font-size:12px;background:#15120e;color:#eee6d8;cursor:pointer;transition:.18s ease;display:flex;flex-direction:column;justify-content:center;gap:2px}
.day-slot:hover:not(:disabled){transform:translateY(-1px);border-color:#8d7041;background:#211a11}
.day-slot.available{border-color:#4d6848;background:#121911}
.day-slot.available .slot-status{color:#9ac58e}
.day-slot.occupied,.day-slot.past{opacity:.48;cursor:not-allowed;border-color:#553a34;background:#15100f}
.day-slot.occupied .slot-status,.day-slot.past .slot-status{color:#c8877e}
.day-slot .slot-time{font-weight:600;color:#d6c29c;font-size:12px}
.day-slot .slot-status{display:block;font-size:10px;margin-top:2px}
.manual-time-wrap{position:relative}
#manualTime{height:48px;font-size:15px;font-weight:600;letter-spacing:.2px;padding-left:14px}
#manualTime:focus{border-color:#b8965d;box-shadow:0 0 0 3px rgba(184,150,93,.08)}
.guest-form{border:1px solid #30281c!important;border-radius:12px!important;padding:16px!important;margin-bottom:12px!important;background:linear-gradient(180deg,#17130f,#12100d);box-shadow:0 8px 20px rgba(0,0,0,.12)}
.guest-form h3{font-size:12px!important;letter-spacing:.08em;text-transform:uppercase;margin:0 0 12px!important;color:#d6c29c!important}
.guest-form .field-label{margin-top:8px}
.therapist-list-title{margin:0 0 10px;color:#d6c29c;font-size:10px;letter-spacing:.08em;text-transform:uppercase}
.card.therapist{min-height:62px;font-size:11px;padding:12px;border-radius:10px}
.card.therapist .small{font-size:9px;color:#777}
#therapistBox{display:block!important;}
.therapist-mode-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-bottom:18px;}
.therapist-mode{height:82px!important;min-height:82px!important;padding:12px 10px!important;box-sizing:border-box;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;font-weight:700;font-size:14px;}
.therapist-mode .small{font-size:12px;margin-top:5px;color:#858585;font-weight:400;}
.therapist-mode.active{border-color:#D6C29C;background:rgba(214,194,156,.09);color:#D6C29C;}
.therapist-options{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;}
.therapist-options[hidden]{display:none!important;}
.therapist-options .card.therapist{height:88px!important;min-height:88px!important;padding:12px 10px!important;box-sizing:border-box;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;font-size:14px;font-weight:700;}
.therapist-options .card.therapist .small{font-size:12px;font-weight:400;color:#9b9b9b;margin-top:5px;}
#therapistBox .empty-option{grid-column:1/-1}
@media(max-width:700px){.therapist-options{grid-template-columns:repeat(2,minmax(0,1fr));}}

/* Compact therapist selector to match the Guest Booking proportions. */
.therapist-mode-grid{gap:8px!important;margin-bottom:12px!important;}
.therapist-mode{height:64px!important;min-height:64px!important;padding:8px!important;font-size:12px!important;border-radius:10px!important;}
.therapist-mode .small{font-size:10px!important;margin-top:3px!important;}
.therapist-options{gap:8px!important;}
.therapist-options .card.therapist{height:68px!important;min-height:68px!important;padding:8px!important;font-size:12px!important;border-radius:10px!important;}
.therapist-options .card.therapist .small{font-size:10px!important;margin-top:3px!important;}

/* Match the compact native calendar icon used by Guest and Customer Booking. */
input[type="date"] {
    color-scheme: dark;
    cursor: pointer;
}
input[type="date"]::-webkit-calendar-picker-indicator {
    width: 13px !important;
    height: 13px !important;
    max-width: 13px;
    max-height: 13px;
    padding: 0 !important;
    margin: 0 2px 0 5px !important;
    opacity: 1;
    cursor: pointer;
    filter: brightness(0) invert(1);
    background-size: 13px 13px;
}

.pax-note{

    margin-top:5px;

    color:#666;

    font-size:7px;

}


/* =========================================================
   NO OPTIONS
   ========================================================= */

.empty-option{

    grid-column:1/-1;

    padding:14px;

    border:
        1px dashed
        #292929;

    border-radius:8px;

    color:#555;

    font-size:8px;

    text-align:center;

}


/* =========================================================
   RESPONSIVE
   ========================================================= */

@media(max-width:900px){

    .booking-layout{

        grid-template-columns:1fr;

    }

    .summary{

        position:static;

    }

}

@media(max-width:600px){

    .header{

        padding:
            10px 4%;

    }

    .brand-text strong{

        font-size:14px;

    }

    .brand-text small{

        display:none;

    }

    .logo{

        width:36px;
        height:36px;

    }

    .hero{

        padding:
            35px 20px
            20px;

    }

    .page{

        padding:
            10px 4%
            50px;

    }

    .box{

        padding:16px;

    }

    .customer-grid{

        grid-template-columns:1fr;

    }

    .full-field{

        grid-column:auto;

    }

    .grid{

        grid-template-columns:
            repeat(
                2,
                minmax(0,1fr)
            );

    }

}


/* =========================================================
   MIZPAH MODERN DIALOG
   ========================================================= */
.mizpah-dialog-backdrop{
    position:fixed;
    inset:0;
    z-index:3000;
    display:flex;
    align-items:center;
    justify-content:center;
    padding:20px;
    background:rgba(0,0,0,.76);
    backdrop-filter:blur(7px);
    opacity:0;
    visibility:hidden;
    transition:opacity .22s ease, visibility .22s ease;
}
.mizpah-dialog-backdrop.is-visible{
    opacity:1;
    visibility:visible;
}
.mizpah-dialog{
    width:min(100%,390px);
    padding:27px 25px 22px;
    border:1px solid rgba(214,194,156,.27);
    border-radius:16px;
    background:linear-gradient(145deg,#1b1915,#100f0d 72%);
    box-shadow:0 24px 80px rgba(0,0,0,.58), inset 0 1px 0 rgba(255,255,255,.035);
    text-align:center;
    transform:translateY(12px) scale(.98);
    transition:transform .22s ease;
}
.mizpah-dialog-backdrop.is-visible .mizpah-dialog{
    transform:translateY(0) scale(1);
}
.mizpah-dialog-mark{
    width:43px;
    height:43px;
    margin:0 auto 14px;
    display:flex;
    align-items:center;
    justify-content:center;
    border:1px solid rgba(214,194,156,.34);
    border-radius:50%;
    color:#D6C29C;
    background:rgba(214,194,156,.08);
    font-family:'Playfair Display',serif;
    font-size:22px;
}
.mizpah-dialog h2{
    margin:0 0 9px;
    color:#D6C29C;
    font-family:'Playfair Display',serif;
    font-size:21px;
    font-weight:600;
}
.mizpah-dialog p{
    margin:0;
    color:#aaa198;
    font-family:'Poppins',sans-serif;
    font-size:11px;
    line-height:1.75;
    overflow-wrap:anywhere;
}
.mizpah-dialog-actions{
    margin-top:22px;
    display:flex;
    justify-content:center;
}
.mizpah-dialog-btn{
    min-width:112px;
    padding:10px 22px;
    border:1px solid #D6C29C;
    border-radius:8px;
    background:#D6C29C;
    color:#17130e;
    font-family:'Poppins',sans-serif;
    font-size:10px;
    font-weight:600;
    cursor:pointer;
    transition:background .18s ease, transform .18s ease;
}
.mizpah-dialog-btn:hover{
    background:#ead8b1;
    transform:translateY(-1px);
}
@media(max-width:480px){
    .mizpah-dialog{padding:24px 20px 20px}
}


/* Balanced therapist selector sizing */
.therapist-mode-grid {
    gap: 10px !important;
    margin-bottom: 14px !important;
}

.therapist-mode {
    min-height: 70px !important;
    height: 70px !important;
    padding: 10px 8px !important;
    font-size: 12px !important;
    line-height: 1.35 !important;
    border-radius: 9px !important;
}

.therapist-mode .small {
    margin-top: 4px !important;
    font-size: 9px !important;
    line-height: 1.35 !important;
}

.therapist-options {
    gap: 9px !important;
    grid-template-columns: repeat(3, minmax(0, 1fr));
}

.therapist-options .card.therapist {
    min-height: 56px !important;
    height: 56px !important;
    padding: 6px 5px !important;
    font-size: 10px !important;
    line-height: 1.2 !important;
    font-weight: 600 !important;
    border-radius: 9px !important;
}

.therapist-options .card.therapist .small {
    margin-top: 2px !important;
    font-size: 8px !important;
    line-height: 1.2 !important;
}

#therapistBox .empty-option {
    font-size: 9px;
    line-height: 1.5;
    padding: 12px;
}

#therapistSelectionNote {
    font-size: 8px !important;
    line-height: 1.5 !important;
}

@media (max-width: 700px) {
    .therapist-options {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}



/* Schedule fields match the Guest Booking date/guest-count row. */
.schedule-date-guests{
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:14px;
    margin-top:18px;
}
.schedule-date-guests .schedule-field{min-width:0;}
.schedule-date-guests .field-label{display:block;margin-top:0;margin-bottom:8px;}
.schedule-date-guests input{width:100%;box-sizing:border-box;}

/* No Preference therapist action. */
.guest-therapist-actions{display:flex;gap:8px;margin:0 0 10px}
.guest-therapist-action{min-height:38px;padding:9px 10px;border:1px solid #292929;border-radius:9px;background:#101010;color:#ccc;font-family:Poppins,sans-serif;font-size:10px;cursor:pointer;transition:.2s ease}
.guest-therapist-action:hover{border-color:rgba(214,194,156,.6);color:#fff}
.guest-therapist-action.active{border-color:#D6C29C;background:rgba(214,194,156,.09);color:#D6C29C;box-shadow:0 0 0 1px rgba(214,194,156,.05)}
#guestDetailsContainer input[name="guest_phone[]"]{inputmode:numeric}
/* Per-guest therapist choices use the same selectable card treatment as Guest Booking. */
.guest-therapist-card .guest-therapist-options{
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:7px;
    margin-top:8px;
}
.guest-therapist-card .guest-therapist-options .guest-therapist{
    display:flex;
    width:100%;
    min-width:0;
    min-height:35px;
    margin:0;
    padding:7px 8px;
    align-items:center;
    justify-content:center;
    border:1px solid #292929;
    border-radius:8px;
    background:#101010;
    color:#ccc;
    font-family:Poppins,sans-serif;
    font-size:9px;
    line-height:1.25;
    text-align:center;
    cursor:pointer;
    user-select:none;
    transition:.2s ease;
    overflow-wrap:anywhere;
}
.guest-therapist-card .guest-therapist-options .guest-therapist:hover:not(.unavailable){
    border-color:rgba(214,194,156,.55);
    color:#fff;
}
.guest-therapist-card .guest-therapist-options .guest-therapist.active{
    border-color:#D6C29C;
    background:rgba(214,194,156,.08);
    color:#D6C29C;
    box-shadow:0 0 0 1px rgba(214,194,156,.04);
}
.guest-therapist-card .guest-therapist-options .guest-therapist.unavailable{
    opacity:.38;
    cursor:not-allowed;
}
@media(max-width:550px){
    .schedule-date-guests{grid-template-columns:minmax(0,1fr);}
    .guest-therapist-card .guest-therapist-options{grid-template-columns:repeat(2,minmax(0,1fr));gap:6px;}
}

/* Match Guest Booking page shell and step typography */
body{background:linear-gradient(rgba(0,0,0,.84),rgba(0,0,0,.94)),url('../assets/images/hero.jpg') center/cover fixed no-repeat;font-family:Poppins,sans-serif}
.box{background:rgba(18,18,18,.93);border:1px solid rgba(255,255,255,.07);border-radius:16px;padding:22px;box-shadow:0 12px 30px rgba(0,0,0,.18)}
.step-header{gap:12px;margin-bottom:19px}
.step-number{width:29px;height:29px;min-width:29px;border-radius:50%;font-size:11px}
.step-header h2{font-family:Poppins,sans-serif;font-size:14px;font-weight:600;color:#D6C29C;margin-bottom:3px}
.step-header p{font-family:Poppins,sans-serif;font-size:10px;color:#666;line-height:1.6}
.field-label{font-size:10px;letter-spacing:.6px;margin:18px 0 9px}
.grid{gap:9px}
.card{font-family:Poppins,sans-serif;font-size:11px;border-radius:10px;min-height:49px}
.empty-option,.message{font-family:Poppins,sans-serif;font-size:10px;color:#666}
/* Guest Booking service selection cards */
.guest-choice-card{border:1px solid rgba(214,194,156,.22);background:rgba(18,16,13,.72);border-radius:12px;padding:16px;margin:14px 0}
.guest-choice-card h3{font-family:'Playfair Display',serif;color:#D6C29C;font-size:17px;font-weight:600;margin:0 0 12px}
.guest-choice-card .field-title{color:#aaa;font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:.6px;margin:18px 0 9px}
.guest-choice-card .field-title:first-of-type{margin-top:0}
.guest-choice-card .selection-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:9px;margin-bottom:8px}
.guest-choice-card .select-card{min-height:42px;position:relative;display:flex;flex-direction:column;justify-content:center;align-items:center;text-align:center;padding:12px 10px;background:#101010;border:1px solid #292929;border-radius:10px;color:#ccc;cursor:pointer;font-family:Poppins,sans-serif;font-size:11px;line-height:1.5;transition:.2s;user-select:none}
.guest-choice-card .select-card:hover{transform:translateY(-1px);border-color:rgba(214,194,156,.55);color:#fff}
.guest-choice-card .select-card.active{border-color:#D6C29C;background:rgba(214,194,156,.08);color:#D6C29C;box-shadow:0 0 0 1px rgba(214,194,156,.04)}
.guest-choice-card .card-price{color:#D6C29C;font-size:10px;margin-top:3px}
.guest-choice-card .service-description{display:block;min-height:38px;margin:10px 0 4px;padding:11px 13px;background:#0e0e0e;border-left:2px solid #D6C29C;border-radius:0 8px 8px 0;color:#aaa;font-family:Poppins,sans-serif;font-size:10px;line-height:1.7}
.guest-choice-card .service-description:empty{display:none}
.guest-choice-card .guest-line-total{color:#D6C29C;font-weight:600;margin-top:12px;font-size:11px}
.guest-choice-card .guest-addons{display:grid;grid-template-columns:repeat(auto-fit,minmax(145px,1fr));gap:9px}
.guest-choice-card .guest-addons .empty-option,.guest-choice-card .guest-addons .message{grid-column:1/-1}
.guest-therapist-card{border:1px solid rgba(214,194,156,.22);background:rgba(18,16,13,.72);border-radius:12px;padding:16px;margin:14px 0}
.guest-therapist-card h3{font-family:'Playfair Display',serif;color:#D6C29C;font-size:17px;margin:0 0 12px}
.guest-therapist-card .select-card{font-family:Poppins,sans-serif;font-size:11px}

</style>

</head>


<body>


<!-- =========================================================
     HEADER
     ========================================================= -->

<header class="header">

    <div class="brand">

        <img
            src="../assets/images/logo.png"
            class="logo"
            alt="Mizpah Wellness Spa"
        >

        <div class="brand-text">

            <small>
                Admin
            </small>

            <strong>
                Walk-in Booking
            </strong>

        </div>

    </div>


    <a
        href="./bookings.php"
        class="back-btn"
        onclick="window.location.href='./bookings.php'; return false;"
    >
        Back to Bookings
    </a>

</header>


<!-- =========================================================
     HERO
     ========================================================= -->

<section class="hero">

    <div class="hero-label">
        Mizpah Wellness Spa
    </div>

    <h1>
        Walk-in Appointment
    </h1>

    <p>
        Create a booking for customers who visit the spa
        without an online reservation.
    </p>

</section>


<!-- =========================================================
     PAGE
     ========================================================= -->

<div class="page">

<form
    method="POST"
    action="walkin-save.php"
    id="bookingForm"
    novalidate
>


<div class="booking-layout">


<!-- =========================================================
     LEFT SIDE
     ========================================================= -->

<div class="form-column">


    <!-- =====================================================
         STEP 1
         ===================================================== -->

    <section class="box">

        <div class="step-header">

            <div class="step-number">
                1
            </div>

            <div>

                <h2>
                    Choose Your Service
                </h2>

                <p>
                    Select a category, service, duration and optional add-ons.
                </p>

            </div>

        </div>


        <label class="field-label">
            Category
        </label>


        <div class="grid">

            <div
                class="card category"
                data-cat="Massage"
            >
                Massage
            </div>

            <div
                class="card category"
                data-cat="Package"
            >
                Package
            </div>

            <div
                class="card category"
                data-cat="Promo"
            >
                Promo
            </div>

        </div>


        <label class="field-label">
            Service
        </label>


        <div
            class="grid"
            id="serviceBox"
        >

            <div class="empty-option">
                Select a category first.
            </div>

        </div>


        <div id="serviceDesc"></div>


        <label class="field-label">
            Duration
        </label>


        <div
            class="grid"
            id="durationBox"
        >

            <div class="empty-option">
                Select a service first.
            </div>

        </div>


        <label class="field-label">
            Optional Add-ons
        </label>


        <div
            class="grid"
            id="addonBox"
        >

            <div class="empty-option">
                Select a service first.
            </div>

        </div>

    </section>


    <!-- =====================================================
         STEP 2
         ===================================================== -->

    <section class="box">

        <div class="step-header">

            <div class="step-number">
                2
            </div>

            <div>

                <h2>
                    Schedule & Room
                </h2>

                <p>
                    Select the room, appointment date,
                    number of guests and available time.
                </p>

            </div>

        </div>


        <label class="field-label">
            Room Type
        </label>


        <div class="grid">

            <div
                class="card room"
                data-room="Single Room"
            >
                Single Room

                <div class="small">
                    For individual appointments
                </div>

            </div>


            <div
                class="card room"
                data-room="Couple Room"
            >
                Couple Room

                <div class="small">
                    Automatically sets 2 guests
                </div>

            </div>

        </div>


        <div class="schedule-grid schedule-date-guests">
            <div class="schedule-field">
                <label class="field-label" for="date">
                    Appointment Date
                </label>
                <input
                    type="date"
                    id="date"
                    required
                >
            </div>

            <div class="schedule-field">
                <label class="field-label" for="pax">
                    Number of Guests
                </label>
                <input
                    type="number"
                    name="pax"
                    id="pax"
                    value="1"
                    min="1"
                    max="4"
                    required
                >
                <div class="pax-note" id="paxNote">
                    Maximum of 4 guests.
                </div>
            </div>
        </div>


        <label class="field-label">
            Appointment Time (AM/PM)
        </label>

        <input
            type="text"
            id="manualTime"
            placeholder="Example: 2:17 PM"
            autocomplete="off"
            required
        >

        <div class="pax-note" id="timeAvailability">
            Select a room, date, service, and duration to view availability.
        </div>
        <div id="dayAvailability" class="day-availability" aria-live="polite"></div>

        <div class="pax-note">
            Operating hours: Weekdays 3 PM–3 AM; weekends 1 PM–3 AM. Last booking starts at 2 AM.
        </div>

    </section>


    <!-- =====================================================
         STEP 3
         ===================================================== -->

    <section class="box">

        <div class="step-header">

            <div class="step-number">
                3
            </div>

            <div>

                <h2>
                    Appointment Time & Therapist
                </h2>

                <p>
                    Select the appointment time and assign a therapist for each guest.
                </p>

            </div>

        </div>


        <div
            class="grid"
            id="therapistBox"
        >

            <div
                class="card therapist active"
                data-id="0"
                data-name="No Preference"
            >

                No Preference

            </div>

        </div>

    </section>


    <!-- =====================================================
         STEP 4
         ===================================================== -->

    <section class="box">

        <div class="step-header">

            <div class="step-number">
                4
            </div>

            <div>

                <h2>
                    Guest Details
                </h2>

                <p>
                    Enter the walk-in customer's contact
                    and payment information.
                </p>

            </div>

        </div>


        <div class="customer-grid">

            <div class="full-field">
                <label class="field-label">Guest Details</label>
                <div id="guestDetailsContainer"></div>
            </div>

            <div class="full-field">
                <label class="field-label" for="walkinEmail">Email Address (Optional)</label>
                <input type="email" name="email" id="walkinEmail" maxlength="150" placeholder="customer@example.com">
                <div class="pax-note">A confirmation email will be sent if an email address is provided.</div>
            </div>

            <div>

                <label class="field-label">
                    Payment Method
                </label>

                <select
                    name="payment_method"
                    required
                >

                    <option value="Cash">
                        Cash
                    </option>

                    <option value="GCash">
                        GCash
                    </option>

                </select>

            </div>


            <div class="full-field">

                <label class="field-label">
                    Notes
                </label>

                <textarea
                    name="notes"
                    placeholder="Optional notes or customer requests..."
                ></textarea>

            </div>


        </div>

    </section>


</div>


<!-- =========================================================
     RIGHT SIDE SUMMARY
     ========================================================= -->

<aside class="summary">


    <div class="summary-label">
        Admin Walk-in
    </div>


    <h3>
        Booking Summary
    </h3>


    <div class="summary-row">

        <span>
            Service
        </span>

        <span id="summaryService">
            —
        </span>

    </div>


    <div class="summary-row">

        <span>
            Duration per Guest
        </span>

        <span id="summaryDuration">
            —
        </span>

    </div>


    <div class="summary-row">

        <span>
            Room
        </span>

        <span id="summaryRoom">
            —
        </span>

    </div>


    <div class="summary-row">

        <span>
            Date
        </span>

        <span id="summaryDate">
            —
        </span>

    </div>


    <div class="summary-row">

        <span>
            Time
        </span>

        <span id="summaryTime">
            —
        </span>

    </div>


    <div class="summary-row">

        <span>
            Guests
        </span>

        <span id="summaryPax">
            1
        </span>

    </div>


    <div class="summary-row">

        <span>
            Therapist
        </span>

        <span id="summaryTherapist">
            No Preference
        </span>

    </div>


    <div class="summary-addons">

        <div class="summary-addons-title">
            Add-ons
        </div>

        <div id="summaryAddons">
            None selected
        </div>

    </div>


    <div class="total-box">

        <div>

            <div class="total-label">
                Estimated Total
            </div>

        </div>

        <div
            class="total-price"
            id="summaryTotal"
        >
            ₱0.00
        </div>

    </div>


    <button
        type="submit"
        class="btn"
    >
        Confirm Walk-in Booking
    </button>


    <div class="admin-note">
        Review the booking information before confirming.
    </div>


</aside>


</div>


<!-- =========================================================
     HIDDEN FORM VALUES
     ========================================================= -->

<input type="hidden" name="customer_name" id="customer_name">
<input type="hidden" name="phone" id="phone">
<input type="hidden" name="guest_selections" id="guest_selections" value="[]">

<input
    type="hidden"
    name="service_id"
    id="service_id"
>

<input
    type="hidden"
    name="service"
    id="service"
>

<input
    type="hidden"
    name="duration"
    id="duration"
>

<input
    type="hidden"
    name="price"
    id="price"
    value="0"
>

<input
    type="hidden"
    name="room_type"
    id="room_type"
>

<input
    type="hidden"
    name="booking_date"
    id="booking_date"
>

<input
    type="hidden"
    name="booking_time"
    id="booking_time"
>

<input
    type="hidden"
    name="therapist"
    id="therapist"
    value="0"
>

<input
    type="hidden"
    name="addons"
    id="addons"
>


</form>

</div>



<div class="mizpah-dialog-backdrop" id="mizpahDialogBackdrop"
     role="presentation" aria-hidden="true">
    <div class="mizpah-dialog" role="alertdialog" aria-modal="true"
         aria-labelledby="mizpahDialogTitle" aria-describedby="mizpahDialogMessage">
        <div class="mizpah-dialog-mark" aria-hidden="true">!</div>
        <h2 id="mizpahDialogTitle">Mizpah Wellness Spa</h2>
        <p id="mizpahDialogMessage"></p>
        <div class="mizpah-dialog-actions">
            <button type="button" class="mizpah-dialog-btn" id="mizpahDialogOk">Okay</button>
        </div>
    </div>
</div>

<script>

/* =========================================================
   MODERN MIZPAH ALERT DIALOG
   Keeps existing alert() validation and booking logic intact.
   ========================================================= */
(function(){
    const backdrop = document.getElementById("mizpahDialogBackdrop");
    const message = document.getElementById("mizpahDialogMessage");
    const okButton = document.getElementById("mizpahDialogOk");
    if (!backdrop || !message || !okButton) return;

    let previousFocus = null;

    function closeMizpahDialog(){
        backdrop.classList.remove("is-visible");
        backdrop.setAttribute("aria-hidden", "true");
        if (previousFocus && typeof previousFocus.focus === "function") {
            previousFocus.focus();
        }
    }

    window.alert = function(text){
        previousFocus = document.activeElement;
        message.textContent = String(text ?? "");
        backdrop.classList.add("is-visible");
        backdrop.setAttribute("aria-hidden", "false");
        okButton.focus();
    };

    okButton.addEventListener("click", closeMizpahDialog);
    backdrop.addEventListener("click", function(event){
        if (event.target === backdrop) closeMizpahDialog();
    });
    document.addEventListener("keydown", function(event){
        if (event.key === "Escape" && backdrop.classList.contains("is-visible")) {
            closeMizpahDialog();
        }
    });
})();


/* =========================================================
   ELEMENTS
   ========================================================= */

const serviceBox =
    document.getElementById("serviceBox");

const durationBox =
    document.getElementById("durationBox");

const addonBox =
    document.getElementById("addonBox");

const therapistBox =
    document.getElementById("therapistBox");

const timeBox =
    document.getElementById("timeBox");

const serviceDesc =
    document.getElementById("serviceDesc");


const serviceIdInput =
    document.getElementById("service_id");

const serviceInput =
    document.getElementById("service");

const durationInput =
    document.getElementById("duration");

const priceInput =
    document.getElementById("price");

const roomInput =
    document.getElementById("room_type");

const bookingDateInput =
    document.getElementById("booking_date");

const bookingTimeInput =
    document.getElementById("booking_time");

const therapistInput =
    document.getElementById("therapist");

const addonsInput =
    document.getElementById("addons");

const dateInput =
    document.getElementById("date");

// Explicitly open the native browser date picker on user interaction.
// This keeps the native calendar while making the whole field clickable.
if (dateInput) {
    dateInput.addEventListener("click", function () {
        if (typeof this.showPicker === "function") {
            try {
                this.showPicker();
            } catch (error) {
                // The browser may already have opened the picker; ignore safely.
            }
        }
    });
}

const paxInput =
    document.getElementById("pax");

const paxNote =
    document.getElementById("paxNote");

const manualTimeInput = document.getElementById("manualTime");
const timeAvailability = document.getElementById("timeAvailability");
const guestDetailsContainer = document.getElementById("guestDetailsContainer");
const legacyCustomerName = document.getElementById("customer_name");
const legacyPhone = document.getElementById("phone");


/* =========================================================
   STATE
   ========================================================= */

let basePrice = 0;

let addonTotal = 0;

let selectedDuration = "";

let selectedCategory = "";

let selectedDate = "";

let selectedTime = "";

let selectedTherapistIds = [];
let therapistLoadRequestId = 0; // Prevent overlapping requests from appending duplicate cards.


/* =========================================================
   HELPERS
   ========================================================= */

function escapeHTML(value){

    if(
        value === null ||
        value === undefined
    ){
        return "";
    }

    return String(value)

        .replace(/&/g,"&amp;")
        .replace(/</g,"&lt;")
        .replace(/>/g,"&gt;")
        .replace(/"/g,"&quot;")
        .replace(/'/g,"&#039;");

}


function money(value){

    return "₱" +
        Number(value || 0)
        .toLocaleString(
            "en-PH",
            {
                minimumFractionDigits:2,
                maximumFractionDigits:2
            }
        );

}


function formatTime24(time){

    if(!time){
        return "—";
    }

    const parts =
        time.split(":");

    let hour =
        parseInt(parts[0]);

    const minute =
        parts[1] || "00";

    const ampm =
        hour >= 12
        ? "PM"
        : "AM";

    hour =
        hour % 12 || 12;

    return (
        hour +
        ":" +
        minute +
        " " +
        ampm
    );

}


function parseManualTime(value) {
    const match = String(value || "").trim().match(/^(0?[1-9]|1[0-2]):([0-5]\d)\s*(AM|PM)$/i);
    if (!match) return null;
    let hour = Number(match[1]);
    const minute = Number(match[2]);
    const period = match[3].toUpperCase();
    if (period === "AM" && hour === 12) hour = 0;
    if (period === "PM" && hour !== 12) hour += 12;
    return String(hour).padStart(2, "0") + ":" + String(minute).padStart(2, "0");
}

function getActualBookingDate(date, time24) {
    if (!date || !time24) return date;
    const [hour] = time24.split(":").map(Number);
    const operatingDay = new Date(date + "T12:00:00");
    const weekday = operatingDay.getDay();
    const openingHour = (weekday === 0 || weekday === 6) ? 13 : 15;

    // After-midnight slots belong to the next calendar date,
    // even though they are part of the previous operating day's schedule.
    if (hour < openingHour) {
        operatingDay.setDate(operatingDay.getDate() + 1);
    }

    const year = operatingDay.getFullYear();
    const month = String(operatingDay.getMonth() + 1).padStart(2, "0");
    const day = String(operatingDay.getDate()).padStart(2, "0");
    return `${year}-${month}-${day}`;
}

function isPastManualTime(date, time24) {
    const [hour, minute] = time24.split(":").map(Number);
    const actualDate = getActualBookingDate(date, time24);
    const selectedDateTime = new Date(`${actualDate}T${String(hour).padStart(2, "0")}:${String(minute).padStart(2, "0")}:00`);
    return selectedDateTime.getTime() <= Date.now();
}

function renderGuestForms() {
    if (!guestDetailsContainer) return;
    const count = roomInput.value === "Couple Room" ? 2 : Math.max(1, Math.min(4, Number(paxInput.value || 1)));
    const previous = Array.from(guestDetailsContainer.querySelectorAll(".guest-form"));
    const saved = previous.map(row => ({
        name: row.querySelector('[name="guest_name[]"]')?.value || "",
        phone: row.querySelector('[name="guest_phone[]"]')?.value || ""
    }));
    guestDetailsContainer.innerHTML = "";
    for (let i = 0; i < count; i++) {
        const guest = saved[i] || {};
        const row = document.createElement("div");
        row.className = "guest-form";
        
        row.innerHTML = `
            <h3 style="color:#D6C29C;margin-bottom:12px">Guest ${i + 1}</h3>
            <label class="field-label">Full Name</label>
            <input type="text" name="guest_name[]" placeholder="Guest ${i + 1} full name" required value="${escapeHTML(guest.name || "")}">
            ${i === 0 ? `
                <label class="field-label">Contact Number</label>
                <input type="tel" name="guest_phone[]" placeholder="09XXXXXXXXX" inputmode="numeric" autocomplete="tel" maxlength="11" minlength="11" pattern="09[0-9]{9}" required value="${escapeHTML(guest.phone || "")}">
            ` : ""}
        `;
        guestDetailsContainer.appendChild(row);
    }
    const firstName = guestDetailsContainer.querySelector('[name="guest_name[]"]');
    const firstPhone = guestDetailsContainer.querySelector('[name="guest_phone[]"]');
    legacyCustomerName.value = firstName?.value || "";
    legacyPhone.value = firstPhone?.value || "";
    guestDetailsContainer.querySelectorAll("input").forEach(input => {
        input.addEventListener("input", () => {
            const phoneField = input.matches('[name="guest_phone[]"]');
            if (phoneField) input.value = input.value.replace(/\D/g, '').slice(0, 11);
            legacyCustomerName.value = guestDetailsContainer.querySelector('[name="guest_name[]"]')?.value || "";
            legacyPhone.value = guestDetailsContainer.querySelector('[name="guest_phone[]"]')?.value || "";
        });
        if (input.matches('[name="guest_phone[]"]')) {
            input.addEventListener('keydown', event => {
                if (['e','E','+','-','.'].includes(event.key)) event.preventDefault();
            });
        }
    });
}

function formatDate(value){

    if(!value){
        return "—";
    }

    const date =
        new Date(
            value + "T00:00:00"
        );

    return date.toLocaleDateString(
        "en-PH",
        {
            month:"short",
            day:"numeric",
            year:"numeric"
        }
    );

}


/* =========================================================
   TOTAL
   ========================================================= */

function updateTotal(){

    const pax = roomInput.value === "Couple Room" ? 2 : Math.max(1, Number(paxInput.value || 1));
    const total =
        (Number(basePrice) + Number(addonTotal)) * pax;

    priceInput.value =
        total.toFixed(2);

    document
    .getElementById(
        "summaryTotal"
    )
    .textContent =
        money(total);

}


/* =========================================================
   ADD-ON SUMMARY
   ========================================================= */

function updateAddonSummary(){

    const selected =
        document.querySelectorAll(
            ".addon.active"
        );

    let names = [];

    let stored = [];

    addonTotal = 0;


    selected.forEach(
        item => {

            const name =
                item.dataset.name;

            const addonPrice =
                Number(
                    item.dataset.price || 0
                );

            names.push(
                name +
                " (" +
                money(addonPrice) +
                ")"
            );

            stored.push(name);

            addonTotal +=
                addonPrice;

        }
    );


    addonsInput.value =
        stored.join(", ");


    document
    .getElementById(
        "summaryAddons"
    )
    .innerHTML =
        names.length
        ?
        names
        .map(
            name =>
            escapeHTML(name)
        )
        .join("<br>")
        :
        "None selected";


    updateTotal();

}


/* =========================================================
   RESET AFTER CATEGORY
   ========================================================= */

function resetServiceSelection(){

    serviceIdInput.value = "";
    serviceInput.value = "";
    durationInput.value = "";

    basePrice = 0;
    addonTotal = 0;

    selectedDuration = "";

    serviceDesc.innerHTML = "";

    durationBox.innerHTML =
        '<div class="empty-option">Select a service first.</div>';

    addonBox.innerHTML =
        '<div class="empty-option">Select a service first.</div>';

    document
    .getElementById(
        "summaryService"
    )
    .textContent = "—";

    document
    .getElementById(
        "summaryDuration"
    )
    .textContent = "—";

    document
    .getElementById(
        "summaryAddons"
    )
    .textContent =
        "None selected";

    addonsInput.value = "";

    updateTotal();

}


/* =========================================================
   CATEGORY
   ========================================================= */

document
.querySelectorAll(
    ".category"
)
.forEach(
    category => {

        category.addEventListener(
            "click",
            function(){

                document
                .querySelectorAll(
                    ".category"
                )
                .forEach(
                    item =>
                    item.classList.remove(
                        "active"
                    )
                );

                this.classList.add(
                    "active"
                );

                selectedCategory = this.dataset.cat || "";

                resetServiceSelection();


                serviceBox.innerHTML =
                    '<div class="empty-option">Loading services...</div>';


                fetch(
                    "../get_services_by_category.php?cat=" +
                    encodeURIComponent(
                        this.dataset.cat
                    )
                )

                .then(
                    response =>
                    response.json()
                )

                .then(
                    data => {

                        serviceBox.innerHTML =
                            "";


                        if(
                            !Array.isArray(data) ||
                            data.length === 0
                        ){

                            serviceBox.innerHTML =
                                '<div class="empty-option">No services available in this category.</div>';

                            return;

                        }


                        data.forEach(
                            service => {

                                const card =
                                    document.createElement(
                                        "div"
                                    );

                                card.className =
                                    "card service";

                                card.dataset.id =
                                    service.id;

                                card.dataset.name =
                                    service.service_name;

                                card.dataset.desc =
                                    service.description || "";

                                card.textContent =
                                    service.service_name;

                                serviceBox
                                .appendChild(
                                    card
                                );

                            }
                        );

                    }
                )

                .catch(
                    () => {

                        serviceBox.innerHTML =
                            '<div class="empty-option">Unable to load services.</div>';

                    }
                );

            }
        );

    }
);


/* =========================================================
   SERVICE
   ========================================================= */

document.addEventListener(
    "click",
    function(e){

        const selected =
            e.target.closest(
                ".service"
            );

        if(!selected){
            return;
        }


        document
        .querySelectorAll(
            ".service"
        )
        .forEach(
            item =>
            item.classList.remove(
                "active"
            )
        );


        selected.classList.add(
            "active"
        );


        serviceIdInput.value =
            selected.dataset.id;

        serviceInput.value =
            selected.dataset.name;


        document
        .getElementById(
            "summaryService"
        )
        .textContent =
            selected.dataset.name;


        if(
            selected.dataset.desc
        ){

            serviceDesc.textContent =
                selected.dataset.desc;

        }else{

            serviceDesc.innerHTML = "";

        }


        basePrice = 0;
        addonTotal = 0;

        durationInput.value = "";
        addonsInput.value = "";

        document
        .getElementById(
            "summaryDuration"
        )
        .textContent = "—";

        document
        .getElementById(
            "summaryAddons"
        )
        .textContent =
            "None selected";

        updateTotal();


        /* DURATION */

        durationBox.innerHTML =
            '<div class="empty-option">Loading durations...</div>';


        fetch(
            "../get_duration.php?id=" +
            encodeURIComponent(
                selected.dataset.id
            )
        )

        .then(
            response =>
            response.json()
        )

        .then(
            data => {

                durationBox.innerHTML =
                    "";


                if(
                    !Array.isArray(data) ||
                    data.length === 0
                ){

                    durationBox.innerHTML =
                        '<div class="empty-option">No duration available.</div>';

                    return;

                }


                const activeCategory = document.querySelector(".category.active")?.dataset.cat || selectedCategory;
                const isPromo = String(activeCategory).trim().toLowerCase() === "promo";
                const autoSelectDuration = isPromo || data.length === 1;

                // Promo always uses its fixed duration. Any service with one
                // available duration is also selected automatically.
                if (autoSelectDuration) {
                    const item = data[0];
                    selectedDuration = String(item.duration || "");
                    durationInput.value = selectedDuration;
                    basePrice = Number(item.price || 0);

                    const fixedCard = document.createElement("div");
                    fixedCard.className = "card duration active duration-locked";
                    fixedCard.dataset.duration = selectedDuration;
                    fixedCard.dataset.price = String(item.price || 0);
                    fixedCard.setAttribute("aria-disabled", "true");
                    fixedCard.innerHTML = escapeHTML(selectedDuration) +
                        '<div class="small">' + money(item.price) + '</div>';
                    durationBox.appendChild(fixedCard);

                    document.getElementById("summaryDuration").textContent = selectedDuration;
                    updateTotal();
                } else {
                    data.forEach(item => {
                        const card = document.createElement("div");
                        card.className = "card duration";
                        card.dataset.duration = item.duration;
                        card.dataset.price = item.price;
                        card.innerHTML = escapeHTML(item.duration) +
                            '<div class="small">' + money(item.price) + '</div>';
                        durationBox.appendChild(card);
                    });
                }
            }
        );


        /* ADD-ONS */

        addonBox.innerHTML =
            '<div class="empty-option">Loading add-ons...</div>';


        fetch(
            "../get_addons.php"
        )

        .then(
            response =>
            response.json()
        )

        .then(
            data => {

                addonBox.innerHTML =
                    "";


                if(
                    !Array.isArray(data) ||
                    data.length === 0
                ){

                    addonBox.innerHTML =
                        '<div class="empty-option">No add-ons available.</div>';

                    return;

                }


                data.forEach(
                    addon => {

                        const card =
                            document.createElement(
                                "div"
                            );

                        card.className =
                            "card addon";

                        card.dataset.name =
                            addon.service_name;

                        card.dataset.price =
                            addon.price;


                        card.innerHTML =
                            escapeHTML(
                                addon.service_name
                            ) +

                            '<div class="small">' +

                            money(
                                addon.price
                            ) +

                            (
                                addon.description
                                ?
                                "<br>" +
                                escapeHTML(
                                    addon.description
                                )
                                :
                                ""
                            ) +

                            "</div>";


                        addonBox
                        .appendChild(
                            card
                        );

                    }
                );

            }
        );

    }
);


/* =========================================================
   DURATION
   ========================================================= */

document.addEventListener(
    "click",
    function(e){

        const selected =
            e.target.closest(
                ".duration"
            );

        const activeCategory = document.querySelector(".category.active")?.dataset.cat || selectedCategory;
        const isPromo = String(activeCategory).trim().toLowerCase() === "promo";

        
    if (!selected) {
        return;
  }

    if (
       isPromo ||
       selected.classList.contains("duration-locked") ||
       selected.getAttribute("aria-disabled") === "true"
    )  {
       e.preventDefault();
       return;
       }
            

        document
        .querySelectorAll(
            ".duration"
        )
        .forEach(
            item =>
            item.classList.remove(
                "active"
            )
        );


        selected.classList.add(
            "active"
        );


        selectedDuration =
            selected.dataset.duration;

        durationInput.value =
            selectedDuration;

        basePrice =
            Number(
                selected.dataset.price || 0
            );


        document
        .getElementById(
            "summaryDuration"
        )
        .textContent =
            selectedDuration;


        updateTotal();


        /*
         If date/time is already selected,
         refresh therapists because duration
         can affect therapist availability.
        */

        if (selectedDate && roomInput.value) {
            if (manualTimeInput.value.trim()) checkManualTime();
            else renderAvailableTimes();
        }

    }
);


/* =========================================================
   ADD-ONS
   ========================================================= */

document.addEventListener(
    "click",
    function(e){

        const selected =
            e.target.closest(
                ".addon"
            );

        if(!selected){
            return;
        }


        selected.classList.toggle(
            "active"
        );


        updateAddonSummary();

    }
);


/* =========================================================
   ROOM
   ========================================================= */

document.addEventListener(
    "click",
    function(e){

        const selected =
            e.target.closest(
                ".room"
            );

        if(!selected){
            return;
        }


        document
        .querySelectorAll(
            ".room"
        )
        .forEach(
            item =>
            item.classList.remove(
                "active"
            )
        );


        selected.classList.add(
            "active"
        );


        const room =
            selected.dataset.room;


        roomInput.value =
            room;


        document
        .getElementById(
            "summaryRoom"
        )
        .textContent =
            room;


        if(
            room === "Couple Room"
        ){

            paxInput.value = 2;

            paxInput.readOnly = true;

            paxNote.textContent =
                "Couple Room automatically uses 2 guests.";

        }else{

            paxInput.readOnly = false;

            if(
                Number(
                    paxInput.value
                ) < 1
            ){

                paxInput.value = 1;

            }

            paxNote.textContent =
                "Maximum of 4 guests.";

        }


        document
        .getElementById(
            "summaryPax"
        )
        .textContent =
            paxInput.value;

        renderGuestForms();
        updateTotal();
        manualTimeInput.value = "";
        if (selectedDate) {
            renderAvailableTimes();
        }

    }
);


/* =========================================================
   PAX
   ========================================================= */

paxInput.addEventListener(
    "input",
    function(){

        let value =
            Number(
                this.value || 1
            );


        if(value < 1){
            value = 1;
        }

        if(value > 4){
            value = 4;
        }


        this.value = value;
        renderGuestForms();


        document
        .getElementById(
            "summaryPax"
        )
        .textContent =
            value;
        updateTotal();

        if (selectedDate && roomInput.value === "Single Room") {
            manualTimeInput.value = "";
            renderAvailableTimes();
        }

    }
);


/* =========================================================
   DATE MINIMUM
   ========================================================= */

const today =
    new Date();

const year =
    today.getFullYear();

const month =
    String(
        today.getMonth() + 1
    ).padStart(2,"0");

const day =
    String(
        today.getDate()
    ).padStart(2,"0");

dateInput.min =
    year +
    "-" +
    month +
    "-" +
    day;

renderGuestForms();


/* =========================================================
   DATE / TIME — ADMIN WALK-IN AVAILABILITY
   Uses admin/check-slot.php (same folder as this page).
   ========================================================= */

async function renderAvailableTimes(){
    selectedDate = dateInput.value;
    bookingDateInput.value = selectedDate;
    selectedTime = "";
    bookingTimeInput.value = "";
    document.getElementById("summaryTime").textContent = "—";
    resetTherapist();
    const dayAvailability = document.getElementById("dayAvailability");
    if (dayAvailability) dayAvailability.innerHTML = "";
    if (!selectedDate || !roomInput.value) {
        timeAvailability.textContent = "Select a room and date to view availability.";
        return;
    }
    if (!selectedDuration || Number(basePrice) <= 0) {
        timeAvailability.textContent = "Select a service and duration to view availability.";
        return;
    }
    timeAvailability.textContent = "Checking available and occupied times for this date...";
    await loadDayAvailability();
}

async function loadDayAvailability(){
    const container = document.getElementById("dayAvailability");
    if (!container || !selectedDate || !roomInput.value || !selectedDuration) return;
    const date = selectedDate;
    const room = roomInput.value;
    const pax = room === "Couple Room" ? 2 : (paxInput.value || "1");
    const weekday = new Date(date + "T12:00:00").getDay();
    const openingHour = (weekday === 0 || weekday === 6) ? 13 : 15;
    const slots = [];

    // Keep one operating session, but visually separate the next calendar day.
    // Every 30-minute starting time is still checked through check-slot.php.
    for (let minute = openingHour * 60; minute <= 26 * 60; minute += 30) {
        const clockMinutes = minute % (24 * 60);
        const h = Math.floor(clockMinutes / 60);
        const m = clockMinutes % 60;
        const time24 = String(h).padStart(2,"0") + ":" + String(m).padStart(2,"0");
        if (isPastManualTime(date, time24)) continue;
        slots.push({
            time24,
            label: formatTime24(time24),
            actualDate: getActualBookingDate(date, time24)
        });
    }

    if (!slots.length) {
        container.innerHTML = '<div class="empty-option">No future booking times remain for this operating date.</div>';
        timeAvailability.textContent = "No future times available for this date.";
        return;
    }

    container.innerHTML = '<div class="empty-option">Loading availability…</div>';

    try {
        const results = await Promise.all(slots.map(async slot => {
            const params = new URLSearchParams({
                date: slot.actualDate,
                time: slot.time24,
                room_type: room,
                duration: selectedDuration,
                pax: String(pax)
            });
            const response = await fetch("check-slot.php?" + params.toString());
            if (!response.ok) throw new Error("Availability endpoint returned HTTP " + response.status);
            return {slot, data: await response.json()};
        }));

        if (date !== dateInput.value || room !== roomInput.value) return;

        container.innerHTML = "";

        const groups = [
            {
                key: date,
                title: "Appointment times · " + formatDate(date),
                items: results.filter(({slot}) => slot.actualDate === date)
            }
        ];
        const afterMidnight = results.filter(({slot}) => slot.actualDate !== date);
        if (afterMidnight.length) {
            groups.push({
                key: afterMidnight[0].slot.actualDate,
                title: "After midnight · " + formatDate(afterMidnight[0].slot.actualDate),
                items: afterMidnight
            });
        }

        groups.forEach(group => {
            if (!group.items.length) return;

            const groupWrap = document.createElement("section");
            groupWrap.className = "day-availability-group";

            const heading = document.createElement("h4");
            heading.className = "day-availability-heading";
            heading.textContent = group.title;

            const grid = document.createElement("div");
            grid.className = "day-availability-grid";

            group.items.forEach(({slot, data}) => {
                const remaining = Number.isFinite(Number(data.remaining))
                    ? Math.max(0, Number(data.remaining))
                    : (data.available ? (room === "Single Room" ? 4 : 1) : 0);
                const requestedPax = room === "Couple Room" ? 2 : Number(pax || 1);
                const canBook = data.available === true || data.available === 1 || data.available === "1";
                const enoughSlots = room === "Couple Room" ? canBook : remaining >= requestedPax;

                const button = document.createElement("button");
                button.type = "button";
                button.className = "day-slot " + (enoughSlots ? "available" : "occupied");
                button.disabled = !enoughSlots;

                const slotStatus = room === "Single Room"
                    ? (remaining + (remaining === 1 ? " slot left" : " slots left"))
                    : (remaining > 0 ? "Couple Room available" : "Occupied / Full");

                button.innerHTML = `<strong>${escapeHTML(slot.label)}</strong><span class="slot-status">${escapeHTML(slotStatus)}</span>`;

                if (enoughSlots) {
                    button.addEventListener("click", () => {
                        manualTimeInput.value = slot.label;
                        checkManualTime();
                    });
                }
                grid.appendChild(button);
            });

            groupWrap.appendChild(heading);
            groupWrap.appendChild(grid);
            container.appendChild(groupWrap);
        });

        timeAvailability.textContent = "Availability for " + formatDate(date) + ". Select an available time or type a custom time below.";
    } catch (error) {
        console.error("Day availability error:", error);
        container.innerHTML = '<div class="empty-option">Could not load daily availability. Check check-slot.php.</div>';
        timeAvailability.textContent = "Unable to load availability for this date.";
    }
}
async function checkManualTime(){
    selectedDate = dateInput.value;
    const time24 = parseManualTime(manualTimeInput.value);
    bookingDateInput.value = time24 ? getActualBookingDate(selectedDate, time24) : selectedDate;
    selectedTime = "";
    bookingTimeInput.value = "";
    document.getElementById("summaryTime").textContent = "—";
    resetTherapist();

    if (!selectedDate || !roomInput.value || !time24) {
        timeAvailability.textContent = "Enter a valid time, such as 2:17 PM.";
        return false;
    }
    if (!selectedDuration || Number(priceInput.value) <= 0) {
        timeAvailability.textContent = "Please select a service and duration first.";
        return false;
    }
    if (isPastManualTime(selectedDate, time24)) {
        timeAvailability.textContent = "That time has already passed. Choose a future time.";
        return false;
    }

    const actualBookingDate = getActualBookingDate(selectedDate, time24);
    bookingDateInput.value = actualBookingDate;
    document.getElementById("summaryDate").textContent = formatDate(actualBookingDate);

    timeAvailability.textContent = "Checking availability...";
    const params = new URLSearchParams({
        date: actualBookingDate, time: time24, room_type: roomInput.value,
        duration: selectedDuration, pax: paxInput.value || "1"
    });
    try {
        const response = await fetch("check-slot.php?" + params.toString());
        if (!response.ok) throw new Error("Availability check failed");
        const data = await response.json();
        if (selectedDate !== dateInput.value || parseManualTime(manualTimeInput.value) !== time24) return false;
        if (isPastManualTime(selectedDate, time24)) {
            timeAvailability.textContent = "That time has already passed.";
            return false;
        }
        const remaining = Number.isFinite(Number(data.remaining))
            ? Math.max(0, Number(data.remaining))
            : null;
        const requestedPax = roomInput.value === "Couple Room"
            ? 2
            : Math.max(1, Number(paxInput.value || 1));
        const slotAvailable = roomInput.value === "Single Room" && remaining !== null
            ? remaining >= requestedPax
            : (data.available === true || data.available === 1 || data.available === "1");

        if (!slotAvailable) {
            if (roomInput.value === "Single Room" && remaining !== null) {
                timeAvailability.textContent = remaining > 0
                    ? `Only ${remaining} slot(s) left at this time. Reduce the guest count or choose another time.`
                    : "No slots left at this time. This slot is already taken.";
            } else {
                timeAvailability.textContent = "That time is occupied, fully booked, or outside operating hours. Choose another time.";
            }
            return false;
        }
        if (roomInput.value === "Single Room" && remaining !== null) {
            timeAvailability.textContent = `${remaining} of 4 guest slots remain available at this time.`;
        }
        selectedTime = time24;
        bookingTimeInput.value = time24;
        bookingDateInput.value = actualBookingDate;
        document.getElementById("summaryDate").textContent = formatDate(actualBookingDate);
        document.getElementById("summaryTime").textContent = formatTime24(time24);
        timeAvailability.textContent = "Time is available.";
        loadTherapists();
        document.dispatchEvent(new Event("walkin-time-confirmed"));
        return true;
    } catch (error) {
        console.error("Walk-in availability error:", error);
        timeAvailability.textContent = "Unable to check availability. Please try again.";
        return false;
    }
}

// Pressing Enter while typing a time must NOT submit the walk-in form.
// It should only finish the field and run the inline availability check.
manualTimeInput.addEventListener("keydown", function(event) {
    if (event.key === "Enter") {
        event.preventDefault();
        this.blur();
    }
});

manualTimeInput.addEventListener("change", checkManualTime);
manualTimeInput.addEventListener("blur", checkManualTime);

dateInput.addEventListener("change", function(){
    selectedDate = this.value;
    bookingDateInput.value = selectedDate;
    document.getElementById("summaryDate").textContent = formatDate(selectedDate);
    manualTimeInput.value = "";
    renderAvailableTimes();
});

/* =========================================================
   RESET THERAPIST
   ========================================================= */

function syncTherapistInputs() {
    const form = document.getElementById("bookingForm");
    form.querySelectorAll(".therapist-id-input").forEach(input => input.remove());

    selectedTherapistIds.forEach(id => {
        const input = document.createElement("input");
        input.type = "hidden";
        input.name = "therapist_ids[]";
        input.value = id;
        input.className = "therapist-id-input";
        form.appendChild(input);
    });

    therapistInput.value = selectedTherapistIds.length ? selectedTherapistIds[0] : "0";
}

function updateTherapistSummary() {
    const summary = document.getElementById("summaryTherapist");
    if (selectedTherapistIds.length === 0) {
        summary.textContent = "No Preference";
        therapistInput.value = "0";
        return;
    }

    const names = selectedTherapistIds.map(id => {
        const card = Array.from(therapistBox.querySelectorAll(".therapist[data-id]"))
            .find(item => String(item.dataset.id) === String(id));
        return card ? card.dataset.name : "Therapist";
    });
    summary.textContent = names.join(", ");
    therapistInput.value = selectedTherapistIds[0];
}

function resetTherapist(){
    selectedTherapistIds = [];
    therapistBox.innerHTML = `
        <div class="therapist-mode-grid">
            <div class="card therapist-mode active" data-mode="none" role="button" tabindex="0">
                No Preference
                <div class="small">Any available therapist</div>
            </div>
            <div class="card therapist-mode" data-mode="choose" role="button" tabindex="0">
                Choose Therapist
                <div class="small">Select a specific therapist</div>
            </div>
        </div>
        <div class="therapist-options" id="therapistOptions" hidden>
            <div class="empty-option">Select an appointment date and time to view available therapists.</div>
        </div>
        <div class="pax-note" id="therapistSelectionNote" style="grid-column:1/-1;margin-top:2px;">
            Select an available therapist.
        </div>
    `;
    syncTherapistInputs();
    updateTherapistSummary();
}

/* =========================================================
   LOAD THERAPISTS
   ========================================================= */

function loadTherapists(){

    // Each refresh invalidates any older in-flight request.
    const requestId = ++therapistLoadRequestId;

    if(
        !selectedDate ||
        !selectedTime
    ){

        return;

    }


    resetTherapist();


    const therapistOptions = document.getElementById("therapistOptions");
    if (!therapistOptions) return;
    therapistOptions.hidden = !therapistBox.querySelector('[data-mode="choose"].active');
    therapistOptions.innerHTML = `
            <div
                class="empty-option"
                id="therapistLoading"
            >
                Checking therapist availability...
            </div>
        `;


    let url =
        "get_available_therapists.php?date=" +
        encodeURIComponent(
            getActualBookingDate(selectedDate, selectedTime)
        ) +
        "&time=" +
        encodeURIComponent(
            selectedTime
        );


    if(selectedDuration){
        url += "&duration=" + encodeURIComponent(selectedDuration);
    }
    url += "&room_type=" + encodeURIComponent(roomInput.value || "Single Room") +
           "&pax=" + encodeURIComponent(roomInput.value === "Couple Room" ? "2" : (paxInput.value || "1"));


    fetch(url)

    .then(
        response => {

            if(!response.ok){

                throw new Error(
                    "Unable to load therapists."
                );

            }

            return response.json();

        }
    )

    .then(
        data => {

            // Ignore stale responses; otherwise older requests can append a second list.
            if (requestId !== therapistLoadRequestId) return;

            const loading =
                document.getElementById(
                    "therapistLoading"
                );

            if(loading){

                loading.remove();

            }


            if (!Array.isArray(data)) {
                console.error("Unexpected therapist response:", data);
                therapistOptions.insertAdjacentHTML("beforeend", '<div class="empty-option">Therapist data format is invalid. Check get_available_therapists.php.</div>');
                return;
            }


            if(
                data.length === 0
            ){

                therapistOptions.insertAdjacentHTML(
                    "beforeend",
                    `
                        <div class="empty-option">
                            No therapists found.
                        </div>
                    `
                );

                return;

            }


            const activeTherapists = data.filter(therapist =>
                !therapist.status || String(therapist.status).toLowerCase() === 'active'
            );

            // Some versions of get_available_therapists.php can return the same
            // therapist more than once. Group by normalized name so the UI shows
            // one card per therapist, while preserving Available if any duplicate
            // record says the therapist is available.
            const therapistMap = new Map();
            activeTherapists.forEach(therapist => {
                const displayName = String(therapist.name || "Unnamed Therapist").trim();
                const key = displayName.toLowerCase().replace(/\s+/g, ' ');
                const rawAvailable = therapist.available;
                const isAvailable = rawAvailable === undefined
                    ? true
                    : !(rawAvailable === false || rawAvailable === 0 || rawAvailable === "0" || String(rawAvailable).toLowerCase() === "false");

                if (!therapistMap.has(key)) {
                    therapistMap.set(key, {
                        ...therapist,
                        name: displayName,
                        available: isAvailable
                    });
                } else if (isAvailable) {
                    therapistMap.get(key).available = true;
                }
            });

            Array.from(therapistMap.values()).forEach(
                therapist => {

                    const card =
                        document.createElement(
                            "div"
                        );


                    const isAvailable = therapist.available !== false;


                    card.className =
                        "card therapist";


                    if(!isAvailable){

                        card.classList.add(
                            "unavailable"
                        );

                    }


                    card.dataset.id =
                        therapist.id;


                    card.dataset.name =
                        therapist.name ||
                        "Unnamed Therapist";


                    /*
                     NAME ONLY.
                     No specialty / specialization.
                    */

                    card.innerHTML = `

                        ${escapeHTML(
                            therapist.name ||
                            "Unnamed Therapist"
                        )}

                        <div class="small">

                            ${
                                isAvailable
                                ?
                                "Available"
                                :
                                "Unavailable"
                            }

                        </div>

                    `;


                    therapistOptions
                    .appendChild(
                        card
                    );

                }
            );

        }
    )

    .catch(
        error => {

            // Ignore errors from requests that are no longer current.
            if (requestId !== therapistLoadRequestId) return;

            const loading =
                document.getElementById(
                    "therapistLoading"
                );

            if(loading){

                loading.remove();

            }


            therapistOptions.insertAdjacentHTML(
                "beforeend",
                `
                    <div class="empty-option">
                        Unable to check therapist availability.
                    </div>
                `
            );

            console.error(
                error
            );

        }
    );

}


/* =========================================================
   THERAPIST
   ========================================================= */

therapistBox.addEventListener("click", function(e) {
    const mode = e.target.closest(".therapist-mode");
    if (mode && therapistBox.contains(mode)) {
        const chooseMode = mode.dataset.mode === "choose";
        therapistBox.querySelectorAll(".therapist-mode").forEach(card => {
            card.classList.toggle("active", card === mode);
        });

        const options = document.getElementById("therapistOptions");
        if (options) options.hidden = !chooseMode;

        if (!chooseMode) {
            selectedTherapistIds = [];
            syncTherapistInputs();
            updateTherapistSummary();
        } else {
            const note = document.getElementById("therapistSelectionNote");
            if (note) note.textContent = "Select an available therapist.";
        }
        return;
    }

    const selected = e.target.closest(".therapist-options .therapist[data-id]");
    if (!selected || !therapistBox.contains(selected)) return;
    if (selected.classList.contains("unavailable")) return;

    const id = String(selected.dataset.id || "");
    const existingIndex = selectedTherapistIds.indexOf(id);
    if (existingIndex !== -1) {
        selectedTherapistIds.splice(existingIndex, 1);
        selected.classList.remove("active");
    } else {
        const expectedCount = roomInput.value === "Couple Room"
            ? 2
            : Math.max(1, Math.min(4, Number(paxInput.value || 1)));
        if (selectedTherapistIds.length >= expectedCount) {
            alert("You can select up to " + expectedCount + " therapist(s) for this booking.");
            return;
        }
        selectedTherapistIds.push(id);
        selected.classList.add("active");
    }

    syncTherapistInputs();
    updateTherapistSummary();
});

/* =========================================================
   FORM VALIDATION
   ========================================================= */

document
.getElementById(
    "bookingForm"
)
.addEventListener(
    "submit",
    function(e){

        // The per-guest booking flow validates its own selections below.
        if (document.getElementById("guest_selections")?.value) return;

        if(
            !serviceIdInput.value ||
            !serviceInput.value
        ){

            e.preventDefault();

            alert(
                "Please select a service."
            );

            return;

        }


        if(
            !durationInput.value ||
            Number(priceInput.value) <= 0
        ){

            e.preventDefault();

            alert(
                "Please select a duration."
            );

            return;

        }


        if(
            !roomInput.value
        ){

            e.preventDefault();

            alert(
                "Please select a room."
            );

            return;

        }


        const expectedTherapists = roomInput.value === "Couple Room"
            ? 2
            : Math.max(1, Math.min(4, Number(paxInput.value || 1)));
        if (selectedTherapistIds.length > 0 && selectedTherapistIds.length !== expectedTherapists) {
            e.preventDefault();
            alert("Select exactly " + expectedTherapists + " therapists for the guests, or choose No Preference.");
            return;
        }

        if(
            !bookingDateInput.value
        ){

            e.preventDefault();

            alert(
                "Please select an appointment date."
            );

            return;

        }


        if(
            !bookingTimeInput.value
        ){

            e.preventDefault();

            alert(
                "Please select an available time."
            );

            return;

        }

    }
);


document.getElementById("bookingForm").addEventListener("submit", async function(event) {
    // Per-guest flow still needs its manually entered time checked first.
    // Once the time is confirmed, let the per-guest submit handler continue.
    if (document.getElementById("guest_selections")?.value && selectedTime && bookingTimeInput.value === selectedTime) return;
    if (!selectedTime || bookingTimeInput.value !== selectedTime) {
        event.preventDefault();
        const valid = await checkManualTime();
        if (!valid) { manualTimeInput.focus(); return; }
        this.requestSubmit();
        return;
    }
    if (!this.reportValidity()) {
        event.preventDefault();
        return;
    }
    const names = Array.from(this.querySelectorAll('[name="guest_name[]"]'));
    const mainGuestPhone = this.querySelector('[name="guest_phone[]"]');
    if (names.length !== Number(paxInput.value) || names.some(el => !el.value.trim()) || !mainGuestPhone?.value.trim()) {
        event.preventDefault();
        alert("Please complete each guest's name and the main guest's contact number.");
    }
});

</script>



<script>
/* =========================================================
   PER-GUEST WALK-IN SELECTIONS
   Mirrors the latest Guest/Customer selection structure while
   keeping walk-in bookings free of Wellness Profile and 2-hour rules.
   ========================================================= */
(function(){
    const form = document.getElementById('bookingForm');
    const formColumn = document.querySelector('.form-column');
    const legacyServiceSection = formColumn?.querySelector('.box');
    const legacyTherapistSection = document.getElementById('therapistBox')?.closest('.box');
    const scheduleSection = Array.from(formColumn?.querySelectorAll('.box') || []).find(box => box.textContent.includes('Schedule & Room'));
    const guestDetails = document.getElementById('guestDetailsContainer');
    const guestSelectionsInput = document.getElementById('guest_selections');
    const emailInput = document.getElementById('walkinEmail');
    if (!form || !formColumn || !scheduleSection || !guestSelectionsInput) return;

    if (legacyServiceSection) legacyServiceSection.style.display = 'none';
    if (legacyTherapistSection) legacyTherapistSection.style.display = 'none';

    // Step 1 is Schedule & Room. Move appointment-time controls into Step 3.
    const scheduleStepNumber = scheduleSection.querySelector('.step-number');
    if (scheduleStepNumber) scheduleStepNumber.textContent = '1';
    const selectionSection = document.createElement('section');
    selectionSection.className = 'box';
    selectionSection.innerHTML = `
      <div class="step-header"><div class="step-number">2</div><div><h2>Choose Your Service</h2><p>Select a category, service, duration and optional add-ons.</p></div></div>
      <div id="walkinGuestSelections"></div>`;
    scheduleSection.insertAdjacentElement('afterend', selectionSection);
    const selectionWrap = selectionSection.querySelector('#walkinGuestSelections');

    const appointmentSection = document.createElement('section');
    appointmentSection.className = 'box';
    appointmentSection.innerHTML = `
      <div class="step-header"><div class="step-number">3</div><div><h2>Appointment Time & Therapist</h2><p>Select the appointment time and assign a therapist for each guest.</p></div></div>
      <div id="walkinAppointmentTime"></div>
      <div id="walkinGuestTherapists"></div>`;
    selectionSection.insertAdjacentElement('afterend', appointmentSection);
    const appointmentTimeWrap = appointmentSection.querySelector('#walkinAppointmentTime');
    const therapistWrap = appointmentSection.querySelector('#walkinGuestTherapists');

    const manualTime = document.getElementById('manualTime');
    if (manualTime) {
      const timeGroup = document.createElement('div');
      timeGroup.className = 'full-field';
      const timeLabel = manualTime.previousElementSibling;
      if (timeLabel && timeLabel.tagName === 'LABEL') timeGroup.appendChild(timeLabel);
      timeGroup.appendChild(manualTime);
      ['timeAvailability', 'dayAvailability'].forEach(id => { const node=document.getElementById(id); if(node) timeGroup.appendChild(node); });
      const operatingNote = Array.from(scheduleSection.querySelectorAll('.pax-note')).find(node => node.textContent.includes('Operating hours:'));
      if (operatingNote) timeGroup.appendChild(operatingNote);
      appointmentTimeWrap.appendChild(timeGroup);
    }

    const guestDetailsSection = Array.from(formColumn.querySelectorAll('.box')).find(box => box.textContent.includes('Guest Details'));
    if (guestDetailsSection) { const n=guestDetailsSection.querySelector('.step-number'); if(n)n.textContent='4'; }

    const state = [];
    const catalogCache = new Map();
    let addonCatalog = null;
    const maxGuests = () => roomInput.value === 'Couple Room' ? 2 : Math.max(1, Math.min(4, Number(paxInput.value || 1)));
    const durationMinutes = value => {
      const v=String(value||'').toLowerCase();
      let m=v.match(/(\d+(?:\.\d+)?)\s*(?:hours?|hrs?)/); if(m)return Math.round(Number(m[1])*60);
      m=v.match(/(\d+)\s*(?:minutes?|mins?)/); if(m)return Number(m[1]);
      const n=Number(v); return Number.isFinite(n)?(n>3?n:Math.round(n*60)):60;
    };
    const html = value => escapeHTML(value);
    const therapistAvailable = t => { const v=t?.available ?? t?.is_available ?? t?.availability ?? t?.status; if(v===undefined||v===null||v==='') return false; if(v===false||v===0||v==='0') return false; const text=String(v).trim().toLowerCase(); if(['false','no','unavailable','busy','off','inactive','day off','day_off'].includes(text)) return false; if(['true','yes','available','active','1'].includes(text)) return true; return true; };
    const fetchJson = async url => { const r=await fetch(url,{cache:'no-store'}); if(!r.ok) throw new Error('Request failed'); return await r.json(); };

    async function getCatalog(category){
      if(catalogCache.has(category)) return catalogCache.get(category);
      try {
        const rows=await fetchJson(new URL('../get_services_by_category.php?cat='+encodeURIComponent(category), window.location.href).toString());
        const normalized=Array.isArray(rows)?rows:(Array.isArray(rows?.services)?rows.services:[]);
        catalogCache.set(category,normalized);
        return normalized;
      } catch(error) { catalogCache.delete(category); throw error; }
    }
    async function getAddons(){ if(!addonCatalog) addonCatalog=await fetchJson('../get_addons.php'); return addonCatalog; }
    function syncLegacyValues(){
      const count=maxGuests();
      const first=state[0]||{};
      serviceIdInput.value=first.service_id||'';
      serviceInput.value=first.service_name||'';
      durationInput.value=first.duration||'';
      selectedDuration=state.reduce((best,item)=>durationMinutes(item.duration)>durationMinutes(best)?item.duration:best,'')||'';
      durationInput.value=selectedDuration;
      const allAddons=state.flatMap(x=>x.addons||[]);
      addonsInput.value=allAddons.map(x=>x.name).join(', ');
      const total=state.reduce((sum,x)=>sum+Number(x.price||0)+(x.addons||[]).reduce((a,b)=>a+Number(b.price||0),0),0);
      priceInput.value=total.toFixed(2);
      basePrice=total; addonTotal=0;
      selectedTherapistIds=[]; // Legacy global therapist validator is intentionally bypassed; per-guest IDs are in JSON.
      therapistInput.value='0';
      guestSelectionsInput.value=JSON.stringify(state.slice(0,count).map((x,i)=>({guest_number:i+1,service_id:x.service_id||0,duration:x.duration||'',addons:(x.addons||[]).map(a=>a.id),therapist_id:x.therapist_id||0,therapist_choice:'no_preference'})));
      document.getElementById('summaryService').textContent=state.slice(0,count).map((x,i)=>x.service_name?`G${i+1}: ${x.service_name}`:`Guest ${i+1}: —`).join(' | ');
      document.getElementById('summaryDuration').textContent=state.slice(0,count).map((x,i)=>x.duration?`G${i+1}: ${x.duration}`:`G${i+1}: —`).join(' | ');
      document.getElementById('summaryAddons').textContent=allAddons.length?allAddons.map((x,i)=>x.name).join(', '):'None selected';
      document.getElementById('summaryTherapist').textContent=state.slice(0,count).map((x,i)=>`G${i+1}: ${x.therapist_name||'No Preference'}`).join(' | ');
      document.getElementById('summaryTotal').textContent=money(total);
      return total;
    }
    function ensureState(){
      const count=maxGuests();
      while(state.length<count) state.push({service_id:0,service_name:'',duration:'',price:0,addons:[],therapist_id:0,therapist_name:'',therapist_choice:'no_preference'});
      state.length=count;
      syncLegacyValues();
    }
    function setMaxDurationAndRecheck(){
      syncLegacyValues();
      if (selectedDate && manualTimeInput.value.trim()) {
        setTimeout(async () => {
          if (typeof checkManualTime === 'function') {
            const valid = await checkManualTime();
            if (valid) document.dispatchEvent(new Event('walkin-time-confirmed'));
          }
        }, 80);
      } else if (selectedDate && roomInput.value && typeof renderAvailableTimes === 'function') {
        setTimeout(() => renderAvailableTimes(), 80);
      }
    }
    function card(title, content){ return `<div class="guest-form" style="border:1px solid rgba(214,194,156,.22);border-radius:12px;padding:16px;margin:14px 0;background:rgba(0,0,0,.12)"><h3 style="color:#D6C29C;margin:0 0 12px">${title}</h3>${content}</div>`; }

    function render(){
      ensureState();
      selectionWrap.innerHTML='';
      therapistWrap.innerHTML='';
      state.forEach((sel,index)=>{
        const guestCard=document.createElement('div');
        guestCard.className='guest-choice-card'; guestCard.dataset.guestIndex=index;
        guestCard.innerHTML=`<h3>Guest ${index+1} Service Selection</h3>
          <div class="field-title">Category</div><div class="selection-grid guest-categories">${['Massage','Package','Promo'].map(c=>`<div class="select-card guest-category ${sel.category===c?'active':''}" data-category="${c}">${c}</div>`).join('')}</div>
          <div class="field-title">Service</div><div class="selection-grid guest-services"><div class="message">Select a category first.</div></div>
          <div class="service-description guest-description">Select a service to view its description.</div>
          <div class="field-title">Duration</div><div class="selection-grid guest-durations"><div class="message">Select a service first.</div></div>
          <div class="field-title">Add-ons <span style="color:#777;text-transform:none">(Optional)</span></div><div class="guest-addons"><div class="message">Select a service first.</div></div>
          <div class="guest-line-total">Guest ${index+1} Total: <span class="guest-total">₱0.00</span></div>`;
        selectionWrap.appendChild(guestCard);
        const therapistCard=document.createElement('div');
        therapistCard.className='guest-therapist-card'; therapistCard.dataset.guestIndex=index;
        therapistCard.innerHTML=`<h3>Guest ${index+1} Therapist</h3><div class="guest-therapists"><div class="guest-therapist-actions"><button type="button" class="guest-therapist-action guest-no-preference active">No Preference</button></div><div class="selection-grid guest-therapist-options"><div class="message">Select appointment time and service first.</div></div></div>`;
        therapistWrap.appendChild(therapistCard);
        if(sel.category) loadServices(index,sel.category).then(()=>{const current=state[index];if(current.service_id){const selected=selectionWrap.querySelector(`[data-guest-index="${index}"] .guest-service[data-id="${current.service_id}"]`);if(selected){const desc=selectionWrap.querySelector(`[data-guest-index="${index}"] .guest-description`);desc.textContent=selected.dataset.desc||'No description available for this service.';}}});
        if(sel.service_id) { loadExistingDurations(index); loadAddons(index); }
        if(selectedDate && selectedTime && sel.service_id) loadTherapists(index);
      });
      syncLegacyValues();
    }

    async function loadServices(index,category){
      const wrap=selectionWrap.querySelector(`[data-guest-index="${index}"]`); if(!wrap)return;
      const list=wrap.querySelector('.guest-services'); list.innerHTML='<div class="empty-option">Loading services...</div>';
      try{
        const rows=await getCatalog(category); list.innerHTML='';
        if(!Array.isArray(rows)||!rows.length){list.innerHTML='<div class="empty-option">No services available.</div>';return;}
        rows.forEach(row=>{const el=document.createElement('div');el.className='select-card guest-service'+(Number(state[index].service_id)===Number(row.id)?' active':'');el.dataset.id=row.id;el.dataset.name=row.service_name;el.dataset.desc=row.description||'';el.textContent=row.service_name;list.appendChild(el);});
      }catch(e){console.error('Walk-in service catalog error:',e);list.innerHTML='<div class="message">Unable to load services. Please check the service catalog endpoint.</div>';}
    }
    async function loadExistingDurations(index){
      const sel=state[index]; const wrap=selectionWrap.querySelector(`[data-guest-index="${index}"]`); if(!wrap||!sel.service_id)return;
      const box=wrap.querySelector('.guest-durations'); box.innerHTML='<div class="empty-option">Loading durations...</div>';
      try{
        const rows=await fetchJson('../get_duration.php?id='+encodeURIComponent(sel.service_id)); box.innerHTML='';
        if(!Array.isArray(rows)||!rows.length){box.innerHTML='<div class="empty-option">No duration available.</div>';return;}
        rows.forEach(row=>{const el=document.createElement('div');el.className='select-card guest-duration'+(String(sel.duration)===String(row.duration)?' active':'');el.dataset.duration=row.duration;el.dataset.price=row.price;el.innerHTML=html(row.duration)+'<div class="small">'+money(row.price)+'</div>';el.addEventListener('click',()=>chooseDuration(index,el));box.appendChild(el);});
      }catch(e){box.innerHTML='<div class="empty-option">Unable to load durations.</div>';}
    }
    async function chooseService(index,serviceCard){
      const sel=state[index]; sel.service_id=Number(serviceCard.dataset.id); sel.service_name=serviceCard.dataset.name; sel.duration=''; sel.price=0; sel.addons=[]; sel.therapist_id=0; sel.therapist_name='';
      const wrap=selectionWrap.querySelector(`[data-guest-index="${index}"]`); wrap.querySelectorAll('.guest-service').forEach(x=>x.classList.toggle('active',x===serviceCard));
      const description=wrap.querySelector('.guest-description');
      description.textContent=serviceCard.dataset.desc||'No description available for this service.';
      const durationBox=wrap.querySelector('.guest-durations'); durationBox.innerHTML='<div class="empty-option">Loading durations...</div>';
      wrap.querySelector('.guest-addons').innerHTML='<div class="empty-option">Loading add-ons...</div>';
      try{
        const rows=await fetchJson('../get_duration.php?id='+encodeURIComponent(sel.service_id)); durationBox.innerHTML='';
        if(!Array.isArray(rows)||!rows.length){durationBox.innerHTML='<div class="empty-option">No duration available.</div>';return;}
        const auto=String(sel.category||'').toLowerCase()==='promo'||rows.length===1;
        rows.forEach((row,i)=>{const el=document.createElement('div');el.className='select-card guest-duration'+(auto&&i===0?' active':'');el.dataset.duration=row.duration;el.dataset.price=row.price;el.innerHTML=html(row.duration)+'<div class="small">'+money(row.price)+'</div>';el.addEventListener('click',()=>chooseDuration(index,el));durationBox.appendChild(el);});
        if(auto) chooseDuration(index,durationBox.querySelector('.guest-duration'));
      }catch(e){durationBox.innerHTML='<div class="empty-option">Unable to load durations.</div>';}
      await loadAddons(index); setMaxDurationAndRecheck();
    }
    function chooseDuration(index,el){
      const sel=state[index]; sel.duration=el.dataset.duration; sel.price=Number(el.dataset.price||0);
      const wrap=selectionWrap.querySelector(`[data-guest-index="${index}"]`); wrap.querySelectorAll('.guest-duration').forEach(x=>x.classList.toggle('active',x===el));
      setMaxDurationAndRecheck();
      if (bookingDateInput.value && bookingTimeInput.value) {
        loadTherapists(index);
      } else if (selectedDate && typeof renderAvailableTimes === 'function') {
        setTimeout(() => renderAvailableTimes(), 80);
      }
    }
    async function loadAddons(index){
      const wrap=selectionWrap.querySelector(`[data-guest-index="${index}"]`); if(!wrap)return;
      const box=wrap.querySelector('.guest-addons');
      try{
        const rows=await getAddons(); box.innerHTML='';
        if(!Array.isArray(rows)||!rows.length){box.innerHTML='<div class="empty-option">No add-ons available.</div>';return;}
        rows.forEach(row=>{const id=Number(row.id);const el=document.createElement('div');el.className='select-card guest-addon'+(state[index].addons.some(a=>a.id===id)?' active':'');el.dataset.id=id;el.dataset.name=row.service_name;el.dataset.price=row.price;el.innerHTML=html(row.service_name)+'<div class="small">'+money(row.price)+'</div>';el.addEventListener('click',()=>{const arr=state[index].addons;const found=arr.findIndex(a=>a.id===id);if(found>=0)arr.splice(found,1);else arr.push({id,name:row.service_name,price:Number(row.price||0)});el.classList.toggle('active');syncLegacyValues();});box.appendChild(el);});
      }catch(e){box.innerHTML='<div class="empty-option">Unable to load add-ons.</div>';}
    }
    function selectGuestTherapist(index,id,name,element){
      const sel=state[index];
      if(state.some((x,i)=>i!==index&&Number(x.therapist_id)===Number(id))){
        alert('That therapist is already assigned to another guest.');
        return;
      }
      sel.therapist_id=Number(id);
      sel.therapist_name=name||'Therapist';
      sel.therapist_choice='assigned';
      const wrap=therapistWrap.querySelector(`[data-guest-index="${index}"]`);
      wrap?.querySelector('.guest-no-preference')?.classList.remove('active');
      wrap?.querySelectorAll('.guest-therapist').forEach(card=>card.classList.toggle('active',card===element));
      syncLegacyValues();
    }

    const therapistRequestTokens = new Map();
    async function loadTherapists(index){
      const sel=state[index]; const wrap=therapistWrap.querySelector(`[data-guest-index="${index}"]`); if(!wrap)return;
      const box=wrap.querySelector('.guest-therapist-options');
      const token=(therapistRequestTokens.get(index)||0)+1; therapistRequestTokens.set(index,token);
      if(!bookingDateInput.value||!bookingTimeInput.value||!sel.duration){box.innerHTML='<div class="empty-option">Select date, time, service, and duration first.</div>';return;}
      box.innerHTML='<div class="empty-option">Checking therapist availability...</div>';
      try{
        const url='get_available_therapists.php?date='+encodeURIComponent(bookingDateInput.value)+'&time='+encodeURIComponent(bookingTimeInput.value)+'&duration='+encodeURIComponent(sel.duration)+'&room_type='+encodeURIComponent(roomInput.value||'Single Room')+'&pax='+encodeURIComponent(maxGuests());
        const rows=await fetchJson(url);
        if(therapistRequestTokens.get(index)!==token)return;
        box.innerHTML='';
        if(!Array.isArray(rows)){box.innerHTML='<div class="empty-option">Unable to read therapist availability.</div>';return;}
        const activeRows=rows.filter(t=>!t.status||!['inactive','disabled'].includes(String(t.status).toLowerCase()));
        if(!activeRows.length){box.innerHTML='<div class="empty-option">No active therapists found for this schedule.</div>';sel.therapist_id=0;sel.therapist_name='';syncLegacyValues();return;}
        const unique=new Map();
        activeRows.forEach(t=>{const id=Number(t.id);if(!id)return;const key=String(id);const available=therapistAvailable(t);if(!unique.has(key)||available)unique.set(key,{...t,_available:available});});
        const current=Array.from(unique.values()).find(t=>Number(t.id)===Number(sel.therapist_id));
        const currentUsed=state.some((x,i)=>i!==index&&Number(x.therapist_id)===Number(sel.therapist_id)&&Number(sel.therapist_id)>0);
        if(sel.therapist_id&&(!current||!current._available||currentUsed)){sel.therapist_id=0;sel.therapist_name='';}
        Array.from(unique.values()).forEach(t=>{
          const id=Number(t.id); const alreadyUsed=state.some((x,i)=>i!==index&&Number(x.therapist_id)===id&&id>0);
          const available=!!t._available&&!alreadyUsed;
          const el=document.createElement('div');
          el.className='guest-therapist'+(Number(sel.therapist_id)===id?' active':'')+(!available?' unavailable':'');
          el.dataset.id=id; el.dataset.name=t.name||'Therapist'; el.setAttribute('role','button');
          el.setAttribute('tabindex',available?'0':'-1'); el.setAttribute('aria-disabled',available?'false':'true');
          el.textContent=t.name||'Unnamed Therapist';
          if(available)el.addEventListener('click',()=>selectGuestTherapist(index,id,t.name,el));
          if(available)el.addEventListener('keydown',event=>{if(event.key==='Enter'||event.key===' '){event.preventDefault();selectGuestTherapist(index,id,t.name,el);}});
          box.appendChild(el);
        });
        if(!sel.therapist_id){sel.therapist_choice='no_preference';wrap.querySelector('.guest-no-preference')?.classList.add('active');}
        syncLegacyValues();
      }catch(e){if(therapistRequestTokens.get(index)!==token)return;box.innerHTML='<div class="empty-option">Unable to check therapist availability. Please refresh the schedule and try again.</div>';console.error('Walk-in therapist availability:',e);}
    }
    function refreshTherapists(){
      state.forEach((sel,index)=>{
        if(sel.service_id && sel.duration && bookingDateInput.value && bookingTimeInput.value) {
          loadTherapists(index);
        }
      });
    }

    document.addEventListener('walkin-time-confirmed', refreshTherapists);
    bookingDateInput.addEventListener('change', () => setTimeout(refreshTherapists, 100));
    bookingTimeInput.addEventListener('change', refreshTherapists);

    selectionWrap.addEventListener('click',async event=>{
      const category=event.target.closest('.guest-category');
      if(category){const wrap=category.closest('[data-guest-index]');const index=Number(wrap.dataset.guestIndex);const sel=state[index];sel.category=category.dataset.category;sel.service_id=0;sel.service_name='';sel.duration='';sel.price=0;sel.addons=[];sel.therapist_id=0;sel.therapist_name='';wrap.querySelectorAll('.guest-category').forEach(x=>x.classList.toggle('active',x===category));wrap.querySelector('.guest-durations').innerHTML='<div class="empty-option">Select a service first.</div>';wrap.querySelector('.guest-addons').innerHTML='<div class="empty-option">Select a service first.</div>';const therapistCard=therapistWrap.querySelector(`[data-guest-index="${index}"]`);if(therapistCard)therapistCard.querySelector('.guest-therapist-options').innerHTML='<div class="message">Select a service and duration first.</div>';await loadServices(index,sel.category);syncLegacyValues();return;}
      const service=event.target.closest('.guest-service');if(service){const index=Number(service.closest('[data-guest-index]').dataset.guestIndex);await chooseService(index,service);return;}
    });
    therapistWrap.addEventListener('click',event=>{
      const noPref=event.target.closest('.guest-no-preference');
      if(noPref){
        const index=Number(noPref.closest('[data-guest-index]').dataset.guestIndex);
        const sel=state[index];
        sel.therapist_id=0; sel.therapist_name=''; sel.therapist_choice='no_preference';
        noPref.classList.add('active');
        noPref.closest('.guest-therapists').querySelectorAll('.guest-therapist').forEach(card=>card.classList.remove('active'));
        syncLegacyValues();
      }
    });

    // Guest name fields are rendered by the existing walk-in form script.
    // Preserve each guest's selections when the guest count or room changes.
    paxInput.addEventListener('change',render); paxInput.addEventListener('input',render);
    roomInput.addEventListener('change',render);
    document.querySelectorAll('.room').forEach(el=>el.addEventListener('click',()=>setTimeout(render,50)));
    dateInput.addEventListener('change',()=>setTimeout(refreshTherapists,100));
    manualTimeInput.addEventListener('change',()=>setTimeout(refreshTherapists,1000));
    manualTimeInput.addEventListener('blur',()=>setTimeout(refreshTherapists,1000));

    let resubmittingAfterTimeCheck = false;
    form.addEventListener('submit', async event=>{
      // The manual time must be checked before the legacy submit listeners run.
      // Stop this submit, verify availability, then submit once more with a
      // confirmed bookingTimeInput value.
      if (resubmittingAfterTimeCheck) {
        resubmittingAfterTimeCheck = false;
      } else if (!selectedTime || !bookingTimeInput.value || bookingTimeInput.value !== selectedTime) {
        event.preventDefault();
        event.stopImmediatePropagation();
        const validTime = await checkManualTime();
        if (!validTime) {
          manualTimeInput.focus();
          return;
        }
        resubmittingAfterTimeCheck = true;
        form.requestSubmit(event.submitter || undefined);
        return;
      }

      ensureState();
      // The old form contains legacy required controls that are hidden in the
      // per-guest layout. Disable browser-native validation and validate only
      // the controls used by the current walk-in flow here.
      if(!roomInput.value){event.preventDefault();alert('Please select a room.');return;}
      if(!bookingDateInput.value){event.preventDefault();alert('Please select an appointment date.');return;}
      if(!bookingTimeInput.value){event.preventDefault();alert('Please select an available appointment time.');return;}
      const paymentSelect=form.querySelector('[name="payment_method"]');
      if(!paymentSelect || !['Cash','GCash'].includes(paymentSelect.value)){event.preventDefault();alert('Please select a payment method.');return;}
      if(state.length!==maxGuests()||state.some((x)=>!x.service_id||!x.duration||Number(x.price)<=0)){
        event.preventDefault();alert('Please select a service and duration for every guest.');return;
      }
      const names=Array.from(form.querySelectorAll('[name="guest_name[]"]'));
      const phone=form.querySelector('[name="guest_phone[]"]')?.value.replace(/\s+/g,'')||'';
      if(names.length!==maxGuests()||names.some(x=>!/^([\p{L}][\p{L}\s.'-]{1,99})$/u.test(x.value.trim()))){event.preventDefault();alert('Please enter a valid full name for every guest.');return;}
      if(!/^09\d{9}$/.test(phone)){event.preventDefault();alert('Please enter Guest 1\'s valid 11-digit Philippine mobile number starting with 09.');return;}
      if(emailInput.value.trim()&&!emailInput.checkValidity()){event.preventDefault();alert('Please enter a valid email address or leave it blank.');return;}
      const selectedTherapists=state.map(x=>x.therapist_id).filter(x=>x>0);
      if(new Set(selectedTherapists).size!==selectedTherapists.length){event.preventDefault();alert('Please choose a different therapist for each guest.');return;}
      syncLegacyValues();
    },true);

    // Keep the time checker informed of the longest guest duration.
    render();
})();
</script>

</body>
</html>