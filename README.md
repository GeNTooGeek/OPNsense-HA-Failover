# OPNsense Single WAN High-Availability Failover

A production-ready, active/passive OPNsense cluster solution that shares a single public IP address (static or DHCP). This system prevents split-brain conditions and IP conflicts through a multi-script architecture with comprehensive logging and safety mechanisms.

## 🚀 Features

- **Single WAN IP Sharing**: Supports both static IP and DHCP configurations
- **Active/Passive Clustering**: Prevents split-brain scenarios with intelligent failover
- **Service Management**: Automatic start/stop of critical services during transitions
- **Health Monitoring**: Multi-stage health checks with configurable retry logic
- **Circuit Breaker**: Prevents rapid failover loops with failure tracking
- **Structured Logging**: JSON-formatted logs for modern observability
- **Dry Run Support**: Safe testing without system changes
- **IPv6 Support**: Optional IPv6 tunnel management

## 📜 Project History

This project builds upon earlier single-script approaches to OPNsense high availability:

**Original Foundation**: [spali's single script solution](https://gist.github.com/spali/2da4f23e488219504b2ada12ac59a7dc) provided the initial framework for CARP-based failover with basic WAN IP management. This pioneering work demonstrated the feasibility of automated failover for single WAN IP scenarios.

**Iterative Development**: [lavacano's enhanced versions](https://gist.github.com/lavacano/a678e65d31df9bec344e572461ed3e10) expanded on the original concept with improved error handling, additional service management, and better logging capabilities. These iterations identified key pain points and reliability issues in production environments.

**Current Solution**: This multi-script architecture represents a complete redesign addressing the limitations discovered in single-script approaches:
- **Separation of Concerns**: Distinct scripts for different phases (boot enforcement, live failover, route management)
- **Production Hardening**: Comprehensive error handling, circuit breakers, and failure tracking
- **Operational Excellence**: Structured logging, dry-run testing, and configuration validation
- **Maintainability**: Centralized configuration and modular design for easier customization

The evolution from single-script to multi-script architecture reflects lessons learned from real-world deployments and the need for enterprise-grade reliability in critical network infrastructure.

## 📋 Prerequisites

### Hardware Requirements
- Two identical OPNsense firewalls
- Dedicated sync interface between firewalls
- Shared network segments for WAN and LAN
- **Recommended**: Configure WAN interfaces with identical MAC addresses for seamless failover

### OPNsense Configuration
Before installing the scripts, configure your firewalls:

#### Primary Firewall
1. Go to **System → High Availability → Settings**
   - Enable HA by checking "Synchronize States"
   - Set the Synchronize Interface (dedicated SYNC interface)
   - Set Synchronize Peer IP to secondary firewall's SYNC IP

2. Go to **System → Settings → Tunables**
   - Create tunable: `net.inet.carp.preempt` = `1`

#### Secondary Firewall
1. Configure HA Sync settings pointing to primary firewall
2. Go to **Interfaces → Virtual IPs → Settings**
   - Check "Disable Preemptive Mode"

#### Both Firewalls
1. Set up CARP VIPs on WAN and LAN interfaces
   - Use unique VHID for each interface
   - Primary: advskew = 0, Secondary: advskew = 100
2. Configure **System → High Availability → Settings** to sync from primary to secondary
3. **Optional but Recommended**: Configure identical MAC addresses on WAN interfaces
   - Go to **Interfaces → [WAN] → General configuration**
   - Set the same MAC address on both firewalls' WAN interfaces
   - This ensures seamless failover without ARP table updates on upstream equipment

## 🛠️ Installation

{% include_relative INSTALLATION.md %}

## 🔧 Troubleshooting

### Common Issues

**Split-brain condition detected**
- Check CARP sync interface connectivity
- Verify sync interface configuration matches on both nodes

**Services not starting/stopping**
- Verify service names in configuration match OPNsense service names
- Check PID file paths are correct
- Review service-specific logs

**Health checks failing**
- Verify target IPs are reachable
- Check ping timeouts are appropriate for your network
- Review external connectivity requirements

### Debug Commands
```bash
# Check CARP status
ifconfig | grep carp

# View current routes
netstat -rn

# Check service status
service status <service_name>

# Manual service control
/usr/local/etc/rc.syshook.d/carp/10-failover.php carp MASTER
```

## 🔒 Security Considerations

- Configuration file has restrictive permissions (600)
- All network operations are validated
- Service control uses proper escaping
- Lock files prevent concurrent execution
- Circuit breaker prevents failover loops

## 📈 Architecture

```
┌─────────────────┐    ┌─────────────────┐
│   Primary FW    │    │  Secondary FW   │
│   (MASTER)      │◄──►│   (BACKUP)      │
│                 │    │                 │
│ ┌─────────────┐ │    │ ┌─────────────┐ │
│ │   WAN IP    │ │    │ │  Services   │ │
│ │  Services   │ │    │ │  Stopped    │ │
│ │  Running    │ │    │ │             │ │
│ └─────────────┘ │    │ └─────────────┘ │
└─────────────────┘    └─────────────────┘
         │                       │
         └───────── LAN ─────────┘
```

## 🤝 Contributing

1. Fork the repository
2. Create a feature branch
3. Test thoroughly with dry-run mode
4. Submit a pull request with detailed description

## 📄 License

This project is licensed under the MIT License - see the LICENSE file for details.

## 🆘 Support

For issues and questions:
1. Check the troubleshooting section
2. Review log files for error details
3. Open an issue with configuration and log excerpts
4. Include OPNsense version and hardware details

---

**⚠️ Important**: Always test in a non-production environment first. Use dry-run mode extensively before deploying to production systems.
