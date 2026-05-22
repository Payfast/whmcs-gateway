# whmcs-gateway

# Gateway WHMCS plugin v2.0.1 for WHMCS 8.13

This is the Payfast Gateway plugin for WHMCS. Please feel free to contact the Payfast support team at
support@payfast.io should you require any assistance.

## Installation

1. **Download the Plugin**

    - Visit the [releases page](https://github.com/Payfast/whmcs-gateway/releases) and
      download [paybatch_payhost_plugin.zip](https://github.com/Payfast/whmcs-gateway/releases/download/v2.0.1/paybatch_payhost_plugin.zip).

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

      #### Required WHMCS API Role Permissions
        - The API Credential used by the PayBatch cron job must be assigned to an API Role that includes the following
          **Allowed API Actions**:
            - `UpdateInvoice`
            - `GetTransactions`
            - `AddInvoicePayment`

      #### WHMCS API Identifier
        - Generated in **WHMCS Admin → System Settings → API Credentials**

      #### WHMCS API Secret
        - Generated together with the API Identifier
        - **Visible only once at creation**
        - Must be stored securely, as it cannot be retrieved later

      #### WHMCS API Access Key (Optional)
        - Only required for WHMCS installations using **strict API access control**
        - Needed when IP whitelisting the PayBatch endpoint is not feasible
        - Includes a direct link to the official documentation: https://developers.whmcs.com/api/access-control/

      #### WHMCS API URL
        - The full URL to the WHMCS API endpoint. Example: `https://yourdomain.com/includes/api.php`

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
