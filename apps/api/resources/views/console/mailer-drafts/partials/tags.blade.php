<div class="alert alert-primary text-center" role="region" aria-label="Usable message tags">
    <strong>USABLE TAGS:</strong>
    <span>[server] [main_domain] [ip] [domain] [domain_sld] [rdns] [to] [date] [name] [rnd]</span>
    <span class="d-block">[rnd(N|A|L|U|S|LU|LN|NU)_NUMBER] [text:a|b|c] ...</span>
    <button type="button" class="btn btn-link btn-sm" id="mailer-tags-help" aria-haspopup="dialog" aria-controls="mailer-tags-dialog" title="Show tag examples">
        <i class="fas fa-question-circle" aria-hidden="true"></i>
        <span class="sr-only">Show tag examples</span>
    </button>
</div>

<dialog id="mailer-tags-dialog" class="mailer-tags-dialog" aria-labelledby="mailer-tags-title">
    <div class="p-3">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <h3 class="h5 mb-0" id="mailer-tags-title">TAGS</h3>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="mailer-tags-close" aria-label="Close tag examples">Close</button>
        </div>
        <div class="row">
            <div class="col-md-6">
                <h4 class="h6">Message and server tags</h4>
                <p><code>[ip]</code> IP · <code>[server]</code> server name · <code>[main_domain]</code> server domain · <code>[domain]</code> selected route domain · <code>[domain_sld]</code> domain without the final TLD · <code>[rdns]</code> reverse DNS</p>
                <p><code>[from]</code> From value · <code>[subject]</code> Subject value · <code>[ids]</code> server ID · <code>[idd]</code> domain ID · <code>[idi]</code> IP ID · <code>[ide]</code> email ID · <code>[to]</code> recipient · <code>[name]</code> email username · <code>[date]</code> current date</p>
                <p><code>[rnd]</code> 18 random characters · <code>[text:a|b|c]</code> choose one value · <code>[base64:text]</code> Base64 encode · <code>[printable:text]</code> quoted-printable encode · <code>#[token]</code> tracking token</p>
                <h4 class="h6">Random tags</h4>
                <p><code>[rndn_N]</code> digits · <code>[rnda_N]</code> letters and digits · <code>[rndl_N]</code> lowercase · <code>[rndu_N]</code> uppercase · <code>[rnds_N]</code> symbols · <code>[rndlu_N]</code> mixed-case letters · <code>[rndln_N]</code> lowercase and digits · <code>[rndun_N]</code> uppercase and digits</p>
            </div>
            <div class="col-md-6">
                <h4 class="h6">Language and boundary tags</h4>
                <p>Replace <code>N</code> with the desired length: <code>[lnggreek_N]</code>, <code>[lngemoji_N]</code>, <code>[lngdevanagari_N]</code>, <code>[lngthai_N]</code>, <code>[lngarabic_N]</code>, <code>[lnglatin_N]</code>, <code>[lngchinese_N]</code>, <code>[lnghiragana_N]</code>, <code>[lngcyrillic_N]</code>, <code>[lngarmenian_N]</code>, <code>[lnggeorgian_N]</code>.</p>
                <p>Boundary tags include <code>[bndn_N]</code>, <code>[bnda_N]</code>, <code>[bndl_N]</code>, <code>[bndu_N]</code>, <code>[bnds_N]</code>, <code>[bndlu_N]</code>, <code>[bndln_N]</code> and <code>[bndun_N]</code>.</p>
                <h4 class="h6">Placeholders</h4>
                <p><code>[placeholder1]</code>, <code>[placeholder2]</code>, then continue with the matching row number. Values come from the Placeholders section and its rotation interval.</p>
                <p class="small text-muted mb-0">These drafts keep tag text unchanged. Drop and Warmup send-time expansion is not connected yet, so this reference does not imply that a test or send is available.</p>
            </div>
        </div>
    </div>
</dialog>
