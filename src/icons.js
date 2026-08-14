// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Icon registry for scholiq (ADR-077 semantic icon vocabulary).
//
// CnAppNav, CnIcon, CnIndexPage / CnDetailPage headers and empty states resolve
// an `icon` by PascalCase name through the registry that `registerIcons()`
// populates. A name that is not registered renders NO icon in the navigation —
// not a fallback glyph — so this file must cover every `icon` the manifests and
// register files name. Keep it in sync when you add a menu entry.
//
// Generated from the app's own manifests; every name is verified to exist in
// vue-material-design-icons.

import Account from 'vue-material-design-icons/Account.vue'
import AccountArrowRightOutline from 'vue-material-design-icons/AccountArrowRightOutline.vue'
import AccountBox from 'vue-material-design-icons/AccountBox.vue'
import AccountBoxOutline from 'vue-material-design-icons/AccountBoxOutline.vue'
import AccountCheckOutline from 'vue-material-design-icons/AccountCheckOutline.vue'
import AccountClockOutline from 'vue-material-design-icons/AccountClockOutline.vue'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import AccountGroupOutline from 'vue-material-design-icons/AccountGroupOutline.vue'
import AccountHeartOutline from 'vue-material-design-icons/AccountHeartOutline.vue'
import AccountMultipleOutline from 'vue-material-design-icons/AccountMultipleOutline.vue'
import AccountOutline from 'vue-material-design-icons/AccountOutline.vue'
import AccountPlusOutline from 'vue-material-design-icons/AccountPlusOutline.vue'
import AccountSchoolOutline from 'vue-material-design-icons/AccountSchoolOutline.vue'
import AccountSwitchOutline from 'vue-material-design-icons/AccountSwitchOutline.vue'
import AccountTieOutline from 'vue-material-design-icons/AccountTieOutline.vue'
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'
import AlertDecagramOutline from 'vue-material-design-icons/AlertDecagramOutline.vue'
import AlertOctagonOutline from 'vue-material-design-icons/AlertOctagonOutline.vue'
import AlertOutline from 'vue-material-design-icons/AlertOutline.vue'
import ApplicationOutline from 'vue-material-design-icons/ApplicationOutline.vue'
import BellOutline from 'vue-material-design-icons/BellOutline.vue'
import BookAlertOutline from 'vue-material-design-icons/BookAlertOutline.vue'
import BookOpenPageVariantOutline from 'vue-material-design-icons/BookOpenPageVariantOutline.vue'
import BookOpenVariant from 'vue-material-design-icons/BookOpenVariant.vue'
import BookOpenVariantOutline from 'vue-material-design-icons/BookOpenVariantOutline.vue'
import Briefcase from 'vue-material-design-icons/Briefcase.vue'
import BriefcaseCheckOutline from 'vue-material-design-icons/BriefcaseCheckOutline.vue'
import BriefcaseOutline from 'vue-material-design-icons/BriefcaseOutline.vue'
import Calendar from 'vue-material-design-icons/Calendar.vue'
import CalendarCheckOutline from 'vue-material-design-icons/CalendarCheckOutline.vue'
import CalendarClockOutline from 'vue-material-design-icons/CalendarClockOutline.vue'
import CalendarMultiselectOutline from 'vue-material-design-icons/CalendarMultiselectOutline.vue'
import CalendarRangeOutline from 'vue-material-design-icons/CalendarRangeOutline.vue'
import CalendarSyncOutline from 'vue-material-design-icons/CalendarSyncOutline.vue'
import CartOutline from 'vue-material-design-icons/CartOutline.vue'
import Cash from 'vue-material-design-icons/Cash.vue'
import CashMultiple from 'vue-material-design-icons/CashMultiple.vue'
import CertificateOutline from 'vue-material-design-icons/CertificateOutline.vue'
import ChartBar from 'vue-material-design-icons/ChartBar.vue'
import ChartBoxOutline from 'vue-material-design-icons/ChartBoxOutline.vue'
import ChartLine from 'vue-material-design-icons/ChartLine.vue'
import ChartLineVariant from 'vue-material-design-icons/ChartLineVariant.vue'
import ChatOutline from 'vue-material-design-icons/ChatOutline.vue'
import Check from 'vue-material-design-icons/Check.vue'
import CheckboxMarkedOutline from 'vue-material-design-icons/CheckboxMarkedOutline.vue'
import CheckCircleOutline from 'vue-material-design-icons/CheckCircleOutline.vue'
import CheckDecagramOutline from 'vue-material-design-icons/CheckDecagramOutline.vue'
import ClipboardCheckOutline from 'vue-material-design-icons/ClipboardCheckOutline.vue'
import ClipboardEditOutline from 'vue-material-design-icons/ClipboardEditOutline.vue'
import ClipboardList from 'vue-material-design-icons/ClipboardList.vue'
import ClipboardListOutline from 'vue-material-design-icons/ClipboardListOutline.vue'
import ClipboardOutline from 'vue-material-design-icons/ClipboardOutline.vue'
import ClipboardTextOutline from 'vue-material-design-icons/ClipboardTextOutline.vue'
import CloseCircleOutline from 'vue-material-design-icons/CloseCircleOutline.vue'
import CommentOutline from 'vue-material-design-icons/CommentOutline.vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import CreditCardOutline from 'vue-material-design-icons/CreditCardOutline.vue'
import CurrencyEur from 'vue-material-design-icons/CurrencyEur.vue'
import DatabaseExportOutline from 'vue-material-design-icons/DatabaseExportOutline.vue'
import DatabaseImportOutline from 'vue-material-design-icons/DatabaseImportOutline.vue'
import DatabaseOutline from 'vue-material-design-icons/DatabaseOutline.vue'
import Door from 'vue-material-design-icons/Door.vue'
import Earth from 'vue-material-design-icons/Earth.vue'
import EmoticonOutline from 'vue-material-design-icons/EmoticonOutline.vue'
import ExportVariant from 'vue-material-design-icons/ExportVariant.vue'
import Eye from 'vue-material-design-icons/Eye.vue'
import FileCertificateOutline from 'vue-material-design-icons/FileCertificateOutline.vue'
import FileChartOutline from 'vue-material-design-icons/FileChartOutline.vue'
import FileDocument from 'vue-material-design-icons/FileDocument.vue'
import FileDocumentEditOutline from 'vue-material-design-icons/FileDocumentEditOutline.vue'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'
import FileImportOutline from 'vue-material-design-icons/FileImportOutline.vue'
import FileOutline from 'vue-material-design-icons/FileOutline.vue'
import FilePresentationBox from 'vue-material-design-icons/FilePresentationBox.vue'
import FileReplaceOutline from 'vue-material-design-icons/FileReplaceOutline.vue'
import FileSign from 'vue-material-design-icons/FileSign.vue'
import FileUploadOutline from 'vue-material-design-icons/FileUploadOutline.vue'
import FlagOutline from 'vue-material-design-icons/FlagOutline.vue'
import FlagVariantOutline from 'vue-material-design-icons/FlagVariantOutline.vue'
import Folder from 'vue-material-design-icons/Folder.vue'
import FolderAccountOutline from 'vue-material-design-icons/FolderAccountOutline.vue'
import FolderOutline from 'vue-material-design-icons/FolderOutline.vue'
import FormatListCheckbox from 'vue-material-design-icons/FormatListCheckbox.vue'
import FormatListNumbered from 'vue-material-design-icons/FormatListNumbered.vue'
import Gauge from 'vue-material-design-icons/Gauge.vue'
import Gavel from 'vue-material-design-icons/Gavel.vue'
import HandHeartOutline from 'vue-material-design-icons/HandHeartOutline.vue'
import HandshakeOutline from 'vue-material-design-icons/HandshakeOutline.vue'
import Heart from 'vue-material-design-icons/Heart.vue'
import History from 'vue-material-design-icons/History.vue'
import Home from 'vue-material-design-icons/Home.vue'
import Human from 'vue-material-design-icons/Human.vue'
import HumanWheelchair from 'vue-material-design-icons/HumanWheelchair.vue'
import InformationOutline from 'vue-material-design-icons/InformationOutline.vue'
import LinkVariant from 'vue-material-design-icons/LinkVariant.vue'
import Lock from 'vue-material-design-icons/Lock.vue'
import MapMarker from 'vue-material-design-icons/MapMarker.vue'
import MapMarkerCheckOutline from 'vue-material-design-icons/MapMarkerCheckOutline.vue'
import MapMarkerPath from 'vue-material-design-icons/MapMarkerPath.vue'
import MapOutline from 'vue-material-design-icons/MapOutline.vue'
import MedalOutline from 'vue-material-design-icons/MedalOutline.vue'
import MessageAlertOutline from 'vue-material-design-icons/MessageAlertOutline.vue'
import MessageTextOutline from 'vue-material-design-icons/MessageTextOutline.vue'
import NotebookOutline from 'vue-material-design-icons/NotebookOutline.vue'
import NoteTextOutline from 'vue-material-design-icons/NoteTextOutline.vue'
import OfficeBuilding from 'vue-material-design-icons/OfficeBuilding.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import Package from 'vue-material-design-icons/Package.vue'
import PackageVariant from 'vue-material-design-icons/PackageVariant.vue'
import Pen from 'vue-material-design-icons/Pen.vue'
import Percent from 'vue-material-design-icons/Percent.vue'
import Phone from 'vue-material-design-icons/Phone.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import PodiumGold from 'vue-material-design-icons/PodiumGold.vue'
import RobotOutline from 'vue-material-design-icons/RobotOutline.vue'
import ScaleBalance from 'vue-material-design-icons/ScaleBalance.vue'
import School from 'vue-material-design-icons/School.vue'
import SchoolOutline from 'vue-material-design-icons/SchoolOutline.vue'
import ShareVariant from 'vue-material-design-icons/ShareVariant.vue'
import ShareVariantOutline from 'vue-material-design-icons/ShareVariantOutline.vue'
import ShieldAccountOutline from 'vue-material-design-icons/ShieldAccountOutline.vue'
import ShieldCheckOutline from 'vue-material-design-icons/ShieldCheckOutline.vue'
import Sitemap from 'vue-material-design-icons/Sitemap.vue'
import SitemapOutline from 'vue-material-design-icons/SitemapOutline.vue'
import Star from 'vue-material-design-icons/Star.vue'
import StarOutline from 'vue-material-design-icons/StarOutline.vue'
import SwapHorizontal from 'vue-material-design-icons/SwapHorizontal.vue'
import Table from 'vue-material-design-icons/Table.vue'
import TableClock from 'vue-material-design-icons/TableClock.vue'
import TableColumn from 'vue-material-design-icons/TableColumn.vue'
import Target from 'vue-material-design-icons/Target.vue'
import TargetVariant from 'vue-material-design-icons/TargetVariant.vue'
import TextBoxOutline from 'vue-material-design-icons/TextBoxOutline.vue'
import Timeline from 'vue-material-design-icons/Timeline.vue'
import TrendingUp from 'vue-material-design-icons/TrendingUp.vue'
import Trophy from 'vue-material-design-icons/Trophy.vue'
import TrophyOutline from 'vue-material-design-icons/TrophyOutline.vue'
import TrophyVariantOutline from 'vue-material-design-icons/TrophyVariantOutline.vue'
import VideoOutline from 'vue-material-design-icons/VideoOutline.vue'
import ViewDashboardOutline from 'vue-material-design-icons/ViewDashboardOutline.vue'
import Web from 'vue-material-design-icons/Web.vue'

export default {
	Account,
	AccountArrowRightOutline,
	AccountBox,
	AccountBoxOutline,
	AccountCheckOutline,
	AccountClockOutline,
	AccountGroup,
	AccountGroupOutline,
	AccountHeartOutline,
	AccountMultipleOutline,
	AccountOutline,
	AccountPlusOutline,
	AccountSchoolOutline,
	AccountSwitchOutline,
	AccountTieOutline,
	AlertCircleOutline,
	AlertDecagramOutline,
	AlertOctagonOutline,
	AlertOutline,
	ApplicationOutline,
	BellOutline,
	BookAlertOutline,
	BookOpenPageVariantOutline,
	BookOpenVariant,
	BookOpenVariantOutline,
	Briefcase,
	BriefcaseCheckOutline,
	BriefcaseOutline,
	Calendar,
	CalendarCheckOutline,
	CalendarClockOutline,
	CalendarMultiselectOutline,
	CalendarRangeOutline,
	CalendarSyncOutline,
	CartOutline,
	Cash,
	CashMultiple,
	CertificateOutline,
	ChartBar,
	ChartBoxOutline,
	ChartLine,
	ChartLineVariant,
	ChatOutline,
	Check,
	CheckCircleOutline,
	CheckDecagramOutline,
	CheckboxMarkedOutline,
	ClipboardCheckOutline,
	ClipboardEditOutline,
	ClipboardList,
	ClipboardListOutline,
	ClipboardOutline,
	ClipboardTextOutline,
	CloseCircleOutline,
	CommentOutline,
	ContentCopy,
	CreditCardOutline,
	CurrencyEur,
	DatabaseExportOutline,
	DatabaseImportOutline,
	DatabaseOutline,
	Door,
	Earth,
	EmoticonOutline,
	ExportVariant,
	Eye,
	FileCertificateOutline,
	FileChartOutline,
	FileDocument,
	FileDocumentEditOutline,
	FileDocumentOutline,
	FileImportOutline,
	FileOutline,
	FilePresentationBox,
	FileReplaceOutline,
	FileSign,
	FileUploadOutline,
	FlagOutline,
	FlagVariantOutline,
	Folder,
	FolderAccountOutline,
	FolderOutline,
	FormatListCheckbox,
	FormatListNumbered,
	Gauge,
	Gavel,
	HandHeartOutline,
	HandshakeOutline,
	Heart,
	History,
	Home,
	Human,
	HumanWheelchair,
	InformationOutline,
	LinkVariant,
	Lock,
	MapMarker,
	MapMarkerCheckOutline,
	MapMarkerPath,
	MapOutline,
	MedalOutline,
	MessageAlertOutline,
	MessageTextOutline,
	NoteTextOutline,
	NotebookOutline,
	OfficeBuilding,
	OpenInNew,
	Package,
	PackageVariant,
	Pen,
	Percent,
	Phone,
	Plus,
	PodiumGold,
	RobotOutline,
	ScaleBalance,
	School,
	SchoolOutline,
	ShareVariant,
	ShareVariantOutline,
	ShieldAccountOutline,
	ShieldCheckOutline,
	Sitemap,
	SitemapOutline,
	Star,
	StarOutline,
	SwapHorizontal,
	Table,
	TableClock,
	TableColumn,
	Target,
	TargetVariant,
	TextBoxOutline,
	Timeline,
	TrendingUp,
	Trophy,
	TrophyOutline,
	TrophyVariantOutline,
	VideoOutline,
	ViewDashboardOutline,
	Web,
}
