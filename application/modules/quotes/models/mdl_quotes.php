<?php

if (!defined('BASEPATH'))
    exit('No direct script access allowed');

/*
 * FusionInvoice
 * 
 * A free and open source web based invoicing system
 *
 * @package		FusionInvoice
 * @author		Jesse Terry
 * @copyright	Copyright (c) 2012 - 2013 FusionInvoice, LLC
 * @license		http://www.fusioninvoice.com/license.txt
 * @link		http://www.fusioninvoice.com
 * 
 */

class Mdl_Quotes extends Response_Model {

    public $table               = 'fi_quotes';
    public $primary_key         = 'fi_quotes.quote_id';
    public $date_modified_field = 'quote_date_modified';

    public function statuses()
    {
        return array(
            '1' => array(
                'label' => lang('draft'),
                'class' => 'label-draft',
                'href'  => 'quotes/status/draft'
            ),
            '2' => array(
                'label' => lang('sent'),
                'class' => 'label-sent',
                'href'  => 'quotes/status/sent'
            ),
            '3' => array(
                'label' => lang('viewed'),
                'class' => 'label-viewed',
                'href'  => 'quotes/status/viewed'
            ),
            '4' => array(
                'label' => lang('approved'),
                'class' => 'label-approved',
                'href'  => 'quotes/status/approved'
            ),
            '5' => array(
                'label' => lang('rejected'),
                'class' => 'label-rejected',
                'href'  => 'quotes/status/rejected'
            ),
            '6' => array(
                'label' => lang('canceled'),
                'class' => 'label-canceled',
                'href'  => 'quotes/status/canceled'
            )
        );
    }

    public function default_select()
    {
        $this->db->select("
            SQL_CALC_FOUND_ROWS fi_quote_custom.*,
            fi_client_custom.*,
            fi_user_custom.*,
            fi_users.user_name, 
			fi_users.user_company,
			fi_users.user_address_1,
			fi_users.user_address_2,
			fi_users.user_city,
			fi_users.user_state,
			fi_users.user_zip,
			fi_users.user_country,
			fi_users.user_phone,
			fi_users.user_fax,
			fi_users.user_mobile,
			fi_users.user_email,
			fi_users.user_web,
			fi_clients.*,
			fi_quote_amounts.quote_amount_id,
			IFNULL(fi_quote_amounts.quote_item_subtotal, '0.00') AS quote_item_subtotal,
			IFNULL(fi_quote_amounts.quote_item_tax_total, '0.00') AS quote_item_tax_total,
			IFNULL(fi_quote_amounts.quote_tax_total, '0.00') AS quote_tax_total,
			IFNULL(fi_quote_amounts.quote_total, '0.00') AS quote_total,
            fi_invoices.invoice_number,
			fi_quotes.*", FALSE);
    }

    public function default_order_by()
    {
        $this->db->order_by('fi_quotes.quote_date_created DESC');
    }

    public function default_join()
    {
        $this->db->join('fi_clients', 'fi_clients.client_id = fi_quotes.client_id');
        $this->db->join('fi_users', 'fi_users.user_id = fi_quotes.user_id');
        $this->db->join('fi_quote_amounts', 'fi_quote_amounts.quote_id = fi_quotes.quote_id', 'left');
        $this->db->join('fi_invoices', 'fi_invoices.invoice_id = fi_quotes.invoice_id', 'left');
        $this->db->join('fi_client_custom', 'fi_client_custom.client_id = fi_clients.client_id', 'left');
        $this->db->join('fi_user_custom', 'fi_user_custom.user_id = fi_users.user_id', 'left');
        $this->db->join('fi_quote_custom', 'fi_quote_custom.quote_id = fi_quotes.quote_id', 'left');
    }

    public function validation_rules()
    {
        return array(
            'client_name'        => array(
                'field' => 'client_name',
                'label' => lang('client'),
                'rules' => 'required'
            ),
            'quote_date_created' => array(
                'field' => 'quote_date_created',
                'label' => lang('quote_date'),
                'rules' => 'required'
            ),
            'invoice_group_id'   => array(
                'field' => 'invoice_group_id',
                'label' => lang('quote_group'),
                'rules' => 'required'
            ),
            'user_id'            => array(
                'field' => 'user_id',
                'label' => lang('user'),
                'rule'  => 'required'
            )
        );
    }

    public function validation_rules_save_quote()
    {
        return array(
            'quote_number'       => array(
                'field' => 'quote_number',
                'label' => lang('quote_number'),
                'rules' => 'required|is_unique[fi_quotes.quote_number' . (($this->id) ? '.quote_id.' . $this->id : '') . ']'
            ),
            'quote_date_created' => array(
                'field' => 'quote_date_created',
                'label' => lang('date'),
                'rules' => 'required'
            ),
            'quote_date_expires' => array(
                'field' => 'quote_date_expires',
                'label' => lang('due_date'),
                'rules' => 'required'
            )
        );
    }

    public function create($db_array = NULL)
    {
        $quote_id = parent::save(NULL, $db_array);

        // Create an quote amount record
        $db_array = array(
            'quote_id' => $quote_id
        );

        $this->db->insert('fi_quote_amounts', $db_array);

        // Create the default invoice tax record if applicable
        if ($this->mdl_settings->setting('default_invoice_tax_rate'))
        {
            $db_array = array(
                'quote_id'              => $quote_id,
                'tax_rate_id'           => $this->mdl_settings->setting('default_invoice_tax_rate'),
                'include_item_tax'      => $this->mdl_settings->setting('default_include_item_tax'),
                'quote_tax_rate_amount' => 0
            );

            $this->db->insert('fi_quote_tax_rates', $db_array);
        }

        return $quote_id;
    }

    public function get_url_key()
    {
        $this->load->helper('string');
        return random_string('unique');
    }

    /**
     * Copies quote items, tax rates, etc from source to target
     * @param int $source_id
     * @param int $target_id
     */
    public function copy_quote($source_id, $target_id)
    {
        $this->load->model('quotes/mdl_quote_items');

        $quote_items = $this->mdl_quote_items->where('quote_id', $source_id)->get()->result();

        foreach ($quote_items as $quote_item)
        {
            $db_array = array(
                'quote_id'         => $target_id,
                'item_tax_rate_id' => $quote_item->item_tax_rate_id,
                'item_name'        => $quote_item->item_name,
                'item_description' => $quote_item->item_description,
                'item_quantity'    => $quote_item->item_quantity,
                'item_price'       => $quote_item->item_price,
                'item_order'       => $quote_item->item_order
            );

            $this->mdl_quote_items->save($target_id, NULL, $db_array);
        }

        $quote_tax_rates = $this->mdl_quote_tax_rates->where('quote_id', $source_id)->get()->result();

        foreach ($quote_tax_rates as $quote_tax_rate)
        {
            $db_array = array(
                'quote_id'              => $target_id,
                'tax_rate_id'           => $quote_tax_rate->tax_rate_id,
                'include_item_tax'      => $quote_tax_rate->include_item_tax,
                'quote_tax_rate_amount' => $quote_tax_rate->quote_tax_rate_amount
            );

            $this->mdl_quote_tax_rates->save($target_id, NULL, $db_array);
        }
    }

    public function db_array()
    {
        $db_array = parent::db_array();

        // Get the client id for the submitted quote
        $this->load->model('clients/mdl_clients');
        $db_array['client_id'] = $this->mdl_clients->client_lookup($db_array['client_name']);
        unset($db_array['client_name']);

        $db_array['quote_date_created'] = date_to_mysql($db_array['quote_date_created']);
        $db_array['quote_date_expires'] = $this->get_date_due($db_array['quote_date_created']);
        $db_array['quote_number']       = $this->get_quote_number($db_array['invoice_group_id']);

        if (!isset($db_array['quote_status_id']))
        {
            $db_array['quote_status_id'] = 1;
        }

        // Generate the unique url key
        $db_array['quote_url_key'] = $this->get_url_key();

        return $db_array;
    }

    public function get_quote_number($invoice_group_id)
    {
        $this->load->model('invoice_groups/mdl_invoice_groups');
        return $this->mdl_invoice_groups->generate_invoice_number($invoice_group_id);
    }

    public function get_date_due($quote_date_created)
    {
        $quote_date_expires = new DateTime($quote_date_created);
        $quote_date_expires->add(new DateInterval('P' . $this->mdl_settings->setting('quotes_expire_after') . 'D'));
        return $quote_date_expires->format('Y-m-d');
    }

    public function delete($quote_id)
    {
        parent::delete($quote_id);

        $this->load->helper('orphan');
        delete_orphans();
    }

    public function is_draft()
    {
        $this->filter_where('quote_status_id', 1);
        return $this;
    }

    public function is_sent()
    {
        $this->filter_where('quote_status_id', 2);
        return $this;
    }

    public function is_viewed()
    {
        $this->filter_where('quote_status_id', 3);
        return $this;
    }

    public function is_approved()
    {
        $this->filter_where('quote_status_id', 4);
        return $this;
    }

    public function is_rejected()
    {
        $this->filter_where('quote_status_id', 5);
        return $this;
    }

    public function is_canceled()
    {
        $this->filter_where('quote_status_id', 6);
        return $this;
    }

    // Used by guest module; includes only sent and viewed
    public function is_open()
    {
        $this->filter_where_in('quote_status_id', array(2, 3));
        return $this;
    }

    public function guest_visible()
    {
        $this->filter_where_in('quote_status_id', array(2, 3, 4, 5));
        return $this;
    }

    public function by_client($client_id)
    {
        $this->filter_where('fi_quotes.client_id', $client_id);
        return $this;
    }

    public function approve_quote_by_key($quote_url_key)
    {
        $this->db->where_in('quote_status_id', array(2, 3));
        $this->db->where('quote_url_key', $quote_url_key);
        $this->db->set('quote_status_id', 4);
        $this->db->update('fi_quotes');
    }

    public function reject_quote_by_key($quote_url_key)
    {
        $this->db->where_in('quote_status_id', array(2, 3));
        $this->db->where('quote_url_key', $quote_url_key);
        $this->db->set('quote_status_id', 5);
        $this->db->update('fi_quotes');
    }

    public function approve_quote_by_id($quote_id)
    {
        $this->db->where_in('quote_status_id', array(2, 3));
        $this->db->where('quote_id', $quote_id);
        $this->db->set('quote_status_id', 4);
        $this->db->update('fi_quotes');
    }

    public function reject_quote_by_id($quote_id)
    {
        $this->db->where_in('quote_status_id', array(2, 3));
        $this->db->where('quote_id', $quote_id);
        $this->db->set('quote_status_id', 5);
        $this->db->update('fi_quotes');
    }

    public function mark_viewed($quote_id)
    {
        $this->db->select('quote_status_id');
        $this->db->where('quote_id', $quote_id);

        $quote = $this->db->get('fi_quotes');

        if ($quote->num_rows())
        {
            if ($quote->row()->quote_status_id == 2)
            {
                $this->db->where('quote_id', $quote_id);
                $this->db->set('quote_status_id', 3);
                $this->db->update('fi_quotes');
            }
        }
    }
    
    public function mark_sent($quote_id)
    {
        $this->db->select('quote_status_id');
        $this->db->where('quote_id', $quote_id);

        $quote = $this->db->get('fi_quotes');

        if ($quote->num_rows())
        {
            if ($quote->row()->quote_status_id == 1)
            {
                $this->db->where('quote_id', $quote_id);
                $this->db->set('quote_status_id', 2);
                $this->db->update('fi_quotes');
            }
        }
    }

}

?>