<?php

add_hook('AdminAreaFooterOutput', 200, function ($vars) {
    logActivity("AdminAreaFooterOutput: admin_header_output");

    return '<style>
                /* Target the 9th checkbox row (auto-convert row) */
                #Payment-Gateway-Config-payhostpaybatch tr:nth-of-type(11) td.fieldlabel {
                    position: relative;
                    top: -32px;
                    background-color: unset;
                }
                /* Add top border above the description for payhostpaybatch_autoconvert */
#Payment-Gateway-Config-payhostpaybatch 
input[name="field[payhostpaybatch_autoconvert]"]
    + hr,
#Payment-Gateway-Config-payhostpaybatch 
input[name="field[payhostpaybatch_autoconvert]"] ~ hr {
    display: none; /* hide inline HR */
}

/* Inject clean border line above description */
/* Remove the inline <hr> */
#Payment-Gateway-Config-payhostpaybatch 
input[name="field[payhostpaybatch_autoconvert]"] ~ hr {
    display: none;
}

/* Inject full-width separator */
#Payment-Gateway-Config-payhostpaybatch 
input[name="field[payhostpaybatch_autoconvert]"] ~ strong::before {
    content: "";
    display: block;
    width: calc(100% + 10000px);   /* extend to full td */
    margin-left: -26px;         /* cancel td padding */
    border-top: 2px solid #fff;
    margin-top: 14px;
    margin-bottom: 12px;
}
    </style>';
});
