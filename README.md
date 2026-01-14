# whmcs-gateway

# Gateway WHMCS plugin v2.0.0 for WHMCS 8.13

This is the Payfast Gateway plugin for WHMCS. Please feel free to contact the Payfast support team at
support@payfast.io should you require any assistance.

## Installation

1. **Download the Plugin**

    - Visit the [releases page](https://github.com/Payfast/whmcs-gateway/releases) and
      download [paybatch_payhost_plugin.zip](https://github.com/Payfast/whmcs-gateway/releases/download/v2.0.0/paybatch_payhost_plugin.zip).

2. **Install the Plugin**

    - Upload the contents of `paybatch_payhost_plugin` into the root of your WHMCS installation.
    - Log in to your WHMCS Admin area.
    - Navigate to **"Setup" > "Apps & Integrations"**.
    - Search for **"Payfast Gateway"**.
    - Click **“Payfast Gateway”** from the search results list, and then click **“Activate”**.

3. **Configure the Plugin**

    - Configure the plugin by entering your Paygate credentials and preferences.
        - **Terminal ID**: Your PayHost terminal ID.
        - **Encryption Key**: Your PayHost encryption key.
        - **PayBatch ID**: Your PayBatch merchant ID.
        - **PayBatch Secret Key**: Your PayBatch secret key.

    - In addition to the standard WHMCS configuration settings, **four additional entries are needed**. These settings
      are required for the PayBatch cron job to mark invoices as paid via the WHMCS API:
        - `WHMCS_API_ACCESS_KEY`: This is a unique key which should be generated randomly. It is stored in
          `"configuration.php"` and `"paybatch_cron_config.php"`.
        - `WHMCS_API_URL`: This should be set as indicated in the sample script, and point to the `api` directory of
          your installation.
        - `WHMCS_API_IDENTIFIER` and `$api_secret`: These are API keys configured in the admin section of your store;
          see https://developers.whmcs.com/api/authentication/. The API secret should be saved when created, as it is
          only shown once when creating new credentials. Use an existing API Role or create a new one that includes the
          role **UpdateInvoice**.

4. **Paybatch Setup**

    - A client who does not have a valid vault id saved will have the vault id stored once they have made a payment
      using Payfast Gateway (provided vaulting is enabled). This vault id is used for future Payfast Gateway payments
      (where only the CVV is entered) and for PayBatch transactions.

4. **Recurring Payments**

    - Recurring payments are triggered using cron jobs and depend on the WHMCS system cron with WHMCS hooks. For more
      about setting up the WHMCS system cron tasks, see:
        - https://docs.whmcs.com/Crons.
        - https://docs.whmcs.com/Custom_Crons_Directory.

5. **PayBatch PAY Hook**

    - Location: `includes/hooks/payhostpaybatch_cron.php`. What it does:
        - Selects Unpaid + NOT previously sent invoices.
        - Creates a PayBatch request.
        - Sends batch to Payfast.
        - Inserts an uploadid record for the batch.
        - Inserts a sent_invoice record for each invoice.
        - Prevents re-sending invoices already in a pending batch.

    - Key behaviour:
        - Only invoices WITHOUT a sent_invoice record are included.
        - Other unpaid invoices may still be included in new batches.
        - Prevents duplicate batch submissions.

6. **PayBatch QUERY Hook**

    - Also inside: `includes/hooks/payhostpaybatch_cron.php`. What it does:
        - Queries each pending batch (uploadid).
        - Processes all TransResult items:
            - Runs full failsafe logic:
                - prevents duplicate TXIDs.
                - prevents paying Paid invoices.
                - prevents overpayments.
        - Marks invoices paid via WHMCS API:
            - Deletes:
                - the uploadid record.
                - all associated sent_invoice records.
    - A batch is cleared only when:`Unprocessed == 0 AND Success == 1`.

7. **Supported Acquiring Banks for Paybatch**

    - ABSA Bank.
    - Standard Bank South Africa.
    - Nedbank.

## Collaboration

Please submit pull requests with any tweaks, features or fixes you would like to share.
